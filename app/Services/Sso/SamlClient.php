<?php

namespace App\Services\Sso;

use App\Models\SsoProvider;
use OneLogin\Saml2\Auth;

/**
 * SAML 2.0 sign-in (ADFS, Entra ID, Shibboleth, Google): signed assertions required,
 * strict validation, request IDs matched, and assertion replay blocked.
 */
class SamlClient
{
    public function auth(SsoProvider $p): Auth
    {
        $c = $p->config;
        $base = url('/sso/'.$p->slug);

        return new Auth([
            'strict' => true,
            'debug' => false,
            'sp' => [
                'entityId' => $base.'/metadata',
                'assertionConsumerService' => ['url' => $base.'/acs', 'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST'],
                'NameIDFormat' => $c['name_id_format'] ?? 'urn:oasis:names:tc:SAML:1.1:nameid-format:unspecified',
            ],
            'idp' => [
                'entityId' => $c['idp_entity_id'] ?? '',
                'singleSignOnService' => ['url' => $c['idp_sso_url'] ?? '', 'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect'],
                'x509cert' => $c['idp_x509'] ?? '',
            ],
            'security' => [
                'wantAssertionsSigned' => true,
                'wantMessagesSigned' => false,
                'wantNameId' => true,
                'rejectUnsolicitedResponsesWithInResponseTo' => true,
                'signatureAlgorithm' => 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256',
                'digestAlgorithm' => 'http://www.w3.org/2001/04/xmlenc#sha256',
                'rejectDeprecatedAlgorithm' => true,
            ],
        ]);
    }
}
