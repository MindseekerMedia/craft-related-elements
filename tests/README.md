# Development checks

Run the database regression checks against a local Craft installation:

```bash
php tests/nested-relations.php /path/to/craft/project
php tests/content-blocks.php /path/to/craft/project
php tests/craft-compatibility.php /path/to/craft/project
```

The first script creates isolated fixture tables, including 50,000 incoming relationships, and removes them afterward. The second requires Craft 5.8 or newer and an existing section entry; it saves real nested Content Block fields and relationships inside a transaction that is rolled back, without writing project-config YAML.

Run `composer install`, `composer check-cs`, and `composer phpstan` for coding-standard and static-analysis checks.

The compatibility script requires an installed plugin and an administrator account in the test project. It creates real section and Matrix fixtures, checks pagination, sidebar/settings rendering, and scoping, then rolls back its database transaction without writing project-config YAML. On Craft versions before 5.8, it also verifies that the Content Block class and table are absent.

Craft 5.0.0 and 5.7.0 have passed both the database regression suite and compatibility smoke checks using isolated MySQL installations with PHP 8.2. Craft 5.11.4 has also passed the database and real Content Block integration checks. These are tested versions, not a claim that every intermediate patch release has been tested.
