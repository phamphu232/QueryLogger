# QueryLogger
Laravel package to log all SQL queries

## 1. Install the package

```
composer require phamphu232/query-logger
```

## 2. Publish configuration and migration

```
php artisan vendor:publish --provider="PhamPhu232\QueryLogger\QueryLoggerServiceProvider"

```

## 3. Configure the package

```
config/query_logger.php
```

## 4. Run migration

```
php artisan migrate

```