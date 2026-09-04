<?php

declare(strict_types=1);

namespace App\Mail;

final class SystemDnsResolver implements DnsResolver
{
    /** @return list<string> */
    public function txt(string $name): array
    {
        $records = @dns_get_record($name, DNS_TXT);

        if ($records === false) {
            return [];
        }

        $values = [];

        foreach ($records as $record) {
            // Long records arrive split into 255-byte strings; "entries"
            // holds the parts, "txt" the joined value.
            $value = $record['txt'] ?? null;

            if (\is_string($value) && $value !== '') {
                $values[] = $value;
            }
        }

        return $values;
    }
}
