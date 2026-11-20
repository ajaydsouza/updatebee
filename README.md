# UpdateBee

<img width="1544" height="500" alt="UpdateBee Social (1544 x 500 px)" src="https://github.com/user-attachments/assets/675cd94e-c665-4acd-8bb5-e5b9d5e185e8" />


Take complete control over your automatic WordPress updates.

- Website: https://updatebee.com
- Source: https://github.com/ronalfy/updatebee/

## Requirements

- PHP 8.0+
- WordPress 6.0+
- Node.js 20+ (for asset builds and JS tests)
- Composer

UpdateBee is a hard fork of <a href="https://wordpress.org/plugins/stops-core-theme-and-plugin-updates/">Easy Updates Manager</a>.

## Setup

```bash
composer install
npm install
npm run build
```

## Generate Production ZIP

```bash
npm run build
grunt
```

## Quality checks

```bash
composer test
composer phpcs
composer phpstan
npm run test:js
npm run lint:js
```

Auto-fix PHP coding standards with `composer phpcbf`.
