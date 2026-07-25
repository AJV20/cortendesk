<?php

namespace App\Services;

use RuntimeException;

/**
 * Raised for any OIDC failure that should be shown to the operator on the login
 * screen. Messages are written to be safe for display: they describe what went
 * wrong without echoing tokens, secrets or raw provider payloads.
 */
class OidcException extends RuntimeException
{
}
