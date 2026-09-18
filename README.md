# WebApps

Browse web apps, site templates and recipes from a Webships catalogue, inside
[Project Browser](https://www.drupal.org/project/project_browser).

A [Webships](https://www.drupal.org/project/webships) site is the catalogue: it
holds the items, and the [Web API](https://www.drupal.org/project/webapi) module
serves them from `/api`. This module is the other half — install it on any Drupal
site and the catalogue's items appear in Project Browser there.

## Install

```bash
ddev composer require drupal/webapps
ddev drush en webapps
```

Project Browser comes with it.

## Point it at a catalogue

The source ships with **no** catalogue address. That is deliberate: a default
would quietly list someone else's catalogue. Set the address of the Webships site
you want to read, in the Project Browser settings for the **Webships catalogue**
source:

```bash
ddev drush config:set project_browser.settings \
  enabled_sources.webships_catalogue.base_uri https://example.com
```

Until it is set, the source says so in the interface rather than showing an empty
list that looks like an empty catalogue. If the address is set but unreachable,
it names the address that failed and logs the reason, instead of failing blankly.

## What it reads

The catalogue is read over JSON:API, from `<base_uri>/api/<resource>`. Each item
becomes a Project Browser entry: its title, its summary, the Composer package
name it can be required by, a link to the item, and the kind of thing it is —
a recipe, a site template, or a module.

## Upgrading from 11.0.x

The 11.0.x line declared its source in `webapps.project_browser.yml`, which is
how Project Browser 1.x took sources. Project Browser 2.x takes source **plugins**
instead, so that file is gone and the source is a PHP class. Nothing carries over
automatically: set the catalogue address after upgrading.
