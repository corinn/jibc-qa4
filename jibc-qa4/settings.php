<?php

/**
 * Load services definition file.
 */
$settings['container_yamls'][] = __DIR__ . '/services.yml';

/**
 * Include the Pantheon-specific settings file.
 *
 * n.b. The settings.pantheon.php file makes some changes
 *      that affect all environments that this site
 *      exists in.  Always include this file, even in
 *      a local development environment, to ensure that
 *      the site settings remain consistent.
 */
include __DIR__ . "/settings.pantheon.php";

/**
 * Skipping permissions hardening will make scaffolding
 * work better, but will also raise a warning when you
 * install Drupal.
 *
 * https://www.drupal.org/project/drupal/issues/3091285
 */
// $settings['skip_permissions_hardening'] = TRUE;

/**
 * If there is a local settings file, then include it
 */
$local_settings = __DIR__ . "/settings.local.php";
if (file_exists($local_settings)) {
  include $local_settings;
}

// Redirect subdomain to a specific path.
if (isset($_ENV['PANTHEON_ENVIRONMENT']) && ($_SERVER['HTTP_HOST'] == 'myjibc.ca') && (php_sapi_name() != "cli")) {
  $newurl = 'https://jibc.ca/myjibc';
  header('HTTP/1.0 302 Moved Permanently');
  header("Location: $newurl");
  exit();
}

// Configure Redis

if (defined(
  'PANTHEON_ENVIRONMENT'
 ) && !\Drupal\Core\Installer\InstallerKernel::installationAttempted(
 ) && extension_loaded('redis')) {
  // Set Redis as the default backend for any cache bin not otherwise specified.
  $settings['cache']['default'] = 'cache.backend.redis';
 
  //phpredis is built into the Pantheon application container.
  $settings['redis.connection']['interface'] = 'PhpRedis';
 
  // These are dynamic variables handled by Pantheon.
  $settings['redis.connection']['host'] = $_ENV['CACHE_HOST'];
  $settings['redis.connection']['port'] = $_ENV['CACHE_PORT'];
  $settings['redis.connection']['password'] = $_ENV['CACHE_PASSWORD'];
 
  $settings['redis_compress_length'] = 100;
  $settings['redis_compress_level'] = 1;
 
  $settings['cache_prefix']['default'] = 'pantheon-redis';
 
  $settings['cache']['bins']['form'] = 'cache.backend.database'; // Use the database for forms
 
  // Apply changes to the container configuration to make better use of Redis.
  // This includes using Redis for the lock and flood control systems, as well
  // as the cache tag checksum. Alternatively, copy the contents of that file
  // to your project-specific services.yml file, modify as appropriate, and
  // remove this line.
  $settings['container_yamls'][] = 'modules/contrib/redis/example.services.yml';
 
  // Allow the services to work before the Redis module itself is enabled.
  $settings['container_yamls'][] = 'modules/contrib/redis/redis.services.yml';
 
  // Manually add the classloader path, this is required for the container
  // cache bin definition below.
  $class_loader->addPsr4('Drupal\\redis\\', 'modules/contrib/redis/src');
 
  // Use redis for container cache.
  // The container cache is used to load the container definition itself, and
  // thus any configuration stored in the container itself is not available
  // yet. These lines force the container cache to use Redis rather than the
  // default SQL cache.
  $settings['bootstrap_container_definition'] = [
    'parameters' => [],
    'services' => [
      'redis.factory' => [
        'class' => 'Drupal\redis\ClientFactory',
      ],
      'cache.backend.redis' => [
        'class' => 'Drupal\redis\Cache\CacheBackendFactory',
        'arguments' => [
          '@redis.factory',
          '@cache_tags_provider.container',
          '@serialization.phpserialize',
        ],
      ],
      'cache.container' => [
        'class' => '\Drupal\redis\Cache\PhpRedis',
        'factory' => ['@cache.backend.redis', 'get'],
        'arguments' => ['container'],
      ],
      'cache_tags_provider.container' => [
        'class' => 'Drupal\redis\Cache\RedisCacheTagsChecksum',
        'arguments' => ['@redis.factory'],
      ],
      'serialization.phpserialize' => [
        'class' => 'Drupal\Component\Serialization\PhpSerialize',
      ],
    ],
  ];
 }

/**
 * If there is a Pantheon Migrations Tool settings file, then include it
 */
$pmt_settings = __DIR__ . "/pmt.settings.php";
if (file_exists($pmt_settings)) {
  include $pmt_settings;
}

$settings['config_sync_directory'] = '../config/sync';

// Automatically generated include for settings managed by ddev.
$ddev_settings = dirname(__FILE__) . '/settings.ddev.php';
if (getenv('IS_DDEV_PROJECT') == 'true' && is_readable($ddev_settings)) {
  require $ddev_settings;
}

/**
 *  Settings for lando local development
 */
$lando_settings = __DIR__ . "/settings.lando.php";
if (file_exists($lando_settings)) {
  include $lando_settings;
}


/**
 * JIBC Workato API Configuration
 *
 * Handles API authentication tokens and endpoints by environment:
 * - QA environments (jibc-qa2 through jibc-qa6): Use hardcoded DEV tokens
 * - dev environment: Use WORKATO_API_TOKEN_DEV from Pantheon secrets
 * - test environment: Use WORKATO_API_TOKEN_TEST from Pantheon secrets
 * - live environment: Use WORKATO_API_TOKEN_PROD from Pantheon secrets
 * 
 * Each environment has different API endpoints:
 * - DEV/QA: https://apim.workato.com/jibc/coursefetch-v1-1/
 * - TEST: https://apim.workato.com/jibc-live-365/coursefetch-v3/
 * - PROD: https://apim.workato.com/jibc_test/coursefetch-v2/
 */

// Default configuration
$settings['jibc_api'] = [
  'workato_auth_token' => NULL, // Will be set below based on environment
  'api_base_url' => NULL, // Will be set below based on environment
  'timeout' => 60,
  'connect_timeout' => 15,
  'log_errors_only' => TRUE,
];

// Environment-specific configuration
if (isset($_ENV['PANTHEON_ENVIRONMENT'])) {
  $pantheon_env = $_ENV['PANTHEON_ENVIRONMENT'];
  
  switch ($pantheon_env) {
    case 'live':
      // Production - use WORKATO_API_TOKEN_PROD from Pantheon secrets
      $prod_token = getenv('WORKATO_API_TOKEN_PROD');
      if ($prod_token) {
        $settings['jibc_api']['workato_auth_token'] = $prod_token;
      } else {
        // Fallback if secret not set (you may want to remove this in production)
        error_log('WARNING: WORKATO_API_TOKEN_PROD not found in Pantheon secrets for live environment');
        $settings['jibc_api']['workato_auth_token'] = 'e4a3f267eaacd8b49a0834fcfcfaeaf5f2776d5cac442af347250ec765e8c62f';
      }
      $settings['jibc_api']['api_base_url'] = 'https://apim.workato.com/jibc_test/coursefetch-v2';
      $settings['jibc_api']['log_errors_only'] = TRUE;
      break;
     
    case 'test':
      // Test environment - use WORKATO_API_TOKEN_TEST from Pantheon secrets
      $test_token = getenv('WORKATO_API_TOKEN_TEST');
      if ($test_token) {
        $settings['jibc_api']['workato_auth_token'] = $test_token;
      } else {
        // Fallback if secret not set
        error_log('WARNING: WORKATO_API_TOKEN_TEST not found in Pantheon secrets for test environment');
        $settings['jibc_api']['workato_auth_token'] = 'e5fb7c85c5f060e2e97ec441b1fb1de89dc6c40965006beb963ca9f7c36455cd';
      }
      $settings['jibc_api']['api_base_url'] = 'https://apim.workato.com/jibc-live-365/coursefetch-v3';
      $settings['jibc_api']['log_errors_only'] = FALSE;
      break;
      
    case 'dev':
      // Dev environment - use WORKATO_API_TOKEN_DEV from Pantheon secrets
      $dev_token = getenv('WORKATO_API_TOKEN_DEV');
      if ($dev_token) {
        $settings['jibc_api']['workato_auth_token'] = $dev_token;
      } else {
        // Fallback if secret not set
        error_log('WARNING: WORKATO_API_TOKEN_DEV not found in Pantheon secrets for dev environment');
        $settings['jibc_api']['workato_auth_token'] = '3a56d63b309a239f61136125bfda34c633534b9b72e9d68d3e56f37c3f086e65';
      }
      $settings['jibc_api']['api_base_url'] = 'https://apim.workato.com/jibc/coursefetch-v1-1';
      $settings['jibc_api']['log_errors_only'] = FALSE;
      break;
     
    default:
      // Handle multidev environments
      if (preg_match('/^jibc-qa[2-6]$/', $pantheon_env)) {
        // QA environments (jibc-qa2 through jibc-qa6) - always use hardcoded DEV token and URLs
        $settings['jibc_api']['workato_auth_token'] = '3a56d63b309a239f61136125bfda34c633534b9b72e9d68d3e56f37c3f086e65';
        $settings['jibc_api']['api_base_url'] = 'https://apim.workato.com/jibc/coursefetch-v1-1';
        $settings['jibc_api']['log_errors_only'] = FALSE;  // Enable logging for QA testing
      } else {
        // Other multidev environments - default to DEV configuration
        $settings['jibc_api']['workato_auth_token'] = '3a56d63b309a239f61136125bfda34c633534b9b72e9d68d3e56f37c3f086e65';
        $settings['jibc_api']['api_base_url'] = 'https://apim.workato.com/jibc/coursefetch-v1-1';
        $settings['jibc_api']['log_errors_only'] = FALSE;
      }
      break;
  }
} else {
  // Local development - use DEV configuration
  $local_token = getenv('WORKATO_API_TOKEN_DEV');
  $settings['jibc_api']['workato_auth_token'] = $local_token ?: '3a56d63b309a239f61136125bfda34c633534b9b72e9d68d3e56f37c3f086e65';
  $settings['jibc_api']['api_base_url'] = 'https://apim.workato.com/jibc/coursefetch-v1-1';
  $settings['jibc_api']['log_errors_only'] = FALSE;  // Enable logging for local dev
}

// Ensure we always have a token and URL set (ultimate fallback to DEV)
if (empty($settings['jibc_api']['workato_auth_token'])) {
  error_log('CRITICAL: No Workato API token configured!');
  $settings['jibc_api']['workato_auth_token'] = '3a56d63b309a239f61136125bfda34c633534b9b72e9d68d3e56f37c3f086e65';
}
if (empty($settings['jibc_api']['api_base_url'])) {
  error_log('CRITICAL: No Workato API URL configured!');
  $settings['jibc_api']['api_base_url'] = 'https://apim.workato.com/jibc/coursefetch-v1-1';
}
