<?php

declare(strict_types=1);

namespace Drupal\webapps\Plugin\ProjectBrowserSource;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\project_browser\Attribute\ProjectBrowserSource;
use Drupal\project_browser\Plugin\ProjectBrowserSourceBase;
use Drupal\project_browser\ProjectBrowser\Filter\TextFilter;
use Drupal\project_browser\ProjectBrowser\Project;
use Drupal\project_browser\ProjectBrowser\ProjectsResultsPage;
use Drupal\project_browser\ProjectType;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lists the items a Webships catalogue serves.
 *
 * A Webships site is a catalogue of web apps, site templates and recipes, built
 * with the Webships Starter site template and served over JSON:API by the Web
 * API module. This source reads that catalogue, so the items show up in Project
 * Browser on any Drupal site.
 *
 * The catalogue address is configuration, not a constant: a team points this at
 * their own Webships site, and there is no default, because a wrong default
 * would quietly list someone else's catalogue. Until it is set, the source says
 * so instead of returning an empty list that looks like an empty catalogue.
 */
#[ProjectBrowserSource(
  id: 'webships_catalogue',
  label: new TranslatableMarkup('Webships catalogue'),
  description: new TranslatableMarkup('Web apps, site templates and recipes from a Webships catalogue.'),
  local_task: [
    'weight' => 1,
  ],
)]
final class WebshipsCatalogue extends ProjectBrowserSourceBase {

  use StringTranslationTrait;

  /**
   * How long a fetched catalogue page is kept, in seconds.
   */
  private const CACHE_LIFETIME = 3600;

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    protected readonly ClientInterface $httpClient,
    protected readonly CacheBackendInterface $cacheBin,
    protected readonly ?LoggerInterface $logger = NULL,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get(ClientInterface::class),
      $container->get('cache.project_browser'),
      $container->get(LoggerChannelFactoryInterface::class)->get('webapps'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      // The base address of the catalogue, for example https://example.com.
      // Empty on purpose: see the class comment.
      'base_uri' => '',
      // The JSON:API resource the catalogue serves its items from.
      'resource' => 'node/webapp',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFilterDefinitions(): array {
    return [
      'search' => new TextFilter('', $this->t('Search')),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getProjects(array $query = []): ProjectsResultsPage {
    $configuration = $this->getConfiguration();
    $base_uri = rtrim((string) $configuration['base_uri'], '/');

    if ($base_uri === '') {
      return $this->createResultsPage([], 0, (string) $this->t('No Webships catalogue is configured yet. Set its address in the Project Browser settings for this source.'));
    }

    $projects = $this->fetchProjects($base_uri, (string) $configuration['resource']);
    if ($projects === NULL) {
      return $this->createResultsPage([], 0, (string) $this->t('The Webships catalogue at @uri could not be read. Check that the site is reachable and serves its API.', ['@uri' => $base_uri]));
    }

    if (!empty($query['machine_name'])) {
      $projects = array_filter(
        $projects,
        static fn (Project $project): bool => $project->machineName === $query['machine_name'],
      );
    }
    if (!empty($query['search'])) {
      $needle = (string) $query['search'];
      $projects = array_filter(
        $projects,
        static fn (Project $project): bool => stripos((string) $project->title, $needle) !== FALSE,
      );
    }

    $projects = array_values($projects);
    $total = count($projects);

    if (array_key_exists('page', $query) && !empty($query['limit'])) {
      $projects = array_chunk($projects, (int) $query['limit'])[$query['page']] ?? [];
    }

    return $this->createResultsPage($projects, $total);
  }

  /**
   * Reads the catalogue and turns its items into projects.
   *
   * @param string $base_uri
   *   The base address of the catalogue, without a trailing slash.
   * @param string $resource
   *   The JSON:API resource to read.
   *
   * @return \Drupal\project_browser\ProjectBrowser\Project[]|null
   *   The projects, or NULL when the catalogue could not be read. An empty
   *   array means the catalogue answered and holds nothing.
   */
  private function fetchProjects(string $base_uri, string $resource): ?array {
    $cache_id = $this->getPluginId() . ':' . $base_uri . ':' . $resource;
    $cached = $this->cacheBin->get($cache_id);
    if ($cached) {
      return $cached->data;
    }

    $url = $base_uri . '/api/' . ltrim($resource, '/');
    try {
      $response = $this->httpClient->request('GET', $url, [
        'headers' => ['Accept' => 'application/vnd.api+json'],
        'timeout' => 15,
      ]);
      $payload = Json::decode((string) $response->getBody());
    }
    catch (\Throwable $exception) {
      // A catalogue that is down must not break the page: report it, and let
      // getProjects() tell the user which address failed.
      $this->logger?->warning('Could not read the Webships catalogue at @url: @message', [
        '@url' => $url,
        '@message' => $exception->getMessage(),
      ]);
      return NULL;
    }

    if (!is_array($payload) || !is_array($payload['data'] ?? NULL)) {
      $this->logger?->warning('The Webships catalogue at @url did not answer with a JSON:API document.', [
        '@url' => $url,
      ]);
      return NULL;
    }

    $projects = [];
    foreach ($payload['data'] as $item) {
      $project = $this->toProject($item, $base_uri);
      if ($project) {
        $projects[] = $project;
      }
    }

    usort(
      $projects,
      static fn (Project $a, Project $b): int => strcasecmp((string) $a->title, (string) $b->title),
    );

    $this->cacheBin->set($cache_id, $projects, time() + self::CACHE_LIFETIME);
    return $projects;
  }

  /**
   * Turns one catalogue item into a project, or NULL when it cannot be used.
   */
  private function toProject(mixed $item, string $base_uri): ?Project {
    if (!is_array($item) || !is_array($item['attributes'] ?? NULL)) {
      return NULL;
    }
    $attributes = $item['attributes'];

    $title = $attributes['title'] ?? NULL;
    if (!is_string($title) || $title === '') {
      return NULL;
    }

    // The catalogue names its items the way the installer does, so an item can
    // be applied by machine name without a second lookup.
    $machine_name = $attributes['field_machine_name']
      ?? $attributes['drupal_internal__nid']
      ?? NULL;
    $machine_name = is_scalar($machine_name) ? (string) $machine_name : $title;

    $summary = $attributes['field_description']['value']
      ?? $attributes['field_description']
      ?? NULL;

    $package = $attributes['field_package'] ?? NULL;

    $path = $attributes['path']['alias'] ?? NULL;
    $url = is_string($path) && $path !== ''
      ? Url::fromUri($base_uri . $path)
      : NULL;

    return new Project(
      logo: NULL,
      isCompatible: TRUE,
      machineName: $machine_name,
      // The catalogue is content, so its wording is not a literal string here.
      // phpcs:disable Drupal.Semantics.FunctionT.NotLiteralString
      body: is_string($summary) && $summary !== '' ? ['summary' => $this->t($summary)] : [],
      title: $this->t($title),
      // phpcs:enable Drupal.Semantics.FunctionT.NotLiteralString
      packageName: is_string($package) ? $package : '',
      url: $url,
      type: $this->itemType($attributes),
    );
  }

  /**
   * Reads which kind of item the catalogue is offering.
   */
  private function itemType(array $attributes): ProjectType {
    $kind = $attributes['field_kind'] ?? '';
    return match (is_string($kind) ? strtolower($kind) : '') {
      'recipe' => ProjectType::Recipe,
      'site_template', 'site template' => ProjectType::Recipe,
      default => ProjectType::Module,
    };
  }

}
