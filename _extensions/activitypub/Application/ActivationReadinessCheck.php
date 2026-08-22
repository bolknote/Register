<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Application;

enum ActivationReadinessCheck: string
{
    case CANONICAL_HTTPS_ORIGIN = 'canonical_https_origin';
    case ROOT_WEBFINGER = 'root_webfinger';
    case BASE_PATH_ROUTING = 'base_path_routing';
    case TLS_TRANSPORT = 'tls_transport';
    case PRIVATE_SECRET_STORAGE = 'private_secret_storage';
    case DATABASE_SCHEMA = 'database_schema';
    case RSA_ROUND_TRIP = 'rsa_round_trip';
    case EXTERNAL_ACTOR_FETCH = 'external_actor_fetch';
    case SIGNED_INBOX_ROUND_TRIP = 'signed_inbox_round_trip';
    case RELEASE_INTEROPERABILITY_GATE = 'release_interoperability_gate';
}
