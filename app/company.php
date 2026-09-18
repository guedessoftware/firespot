<?php
// Helper functions for managing the company profile (brand info)

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/schema_guard.php';

/**
 * Ensure the company_profile table exists with the expected structure.
 */
function company_ensure_table(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }

    runtime_schema_require($pdo, 'company_profile', ['id','name','subtitle','logo_letter']);

    $ensured = true;
}

/**
 * Retrieve the company profile, bootstrapping defaults if necessary.
 */
function company_get(): array
{
    $pdo = db();
    company_ensure_table($pdo);

    $stmt = $pdo->prepare('SELECT * FROM company_profile WHERE id = 1 LIMIT 1');
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        $defaults = [
            'id' => 1,
            'name' => 'FireSpot',
            'subtitle' => 'Wi-Fi seguro e rápido',
            'logo_letter' => 'F',
            'support_phone' => null,
            'support_whatsapp' => null,
            'support_email' => null,
            'support_site' => null,
        ];

        $insert = $pdo->prepare('INSERT INTO company_profile (id, name, subtitle, logo_letter, support_phone, support_whatsapp, support_email, support_site)
            VALUES (:id, :name, :subtitle, :logo_letter, :support_phone, :support_whatsapp, :support_email, :support_site)');
        $insert->execute([
            ':id' => $defaults['id'],
            ':name' => $defaults['name'],
            ':subtitle' => $defaults['subtitle'],
            ':logo_letter' => $defaults['logo_letter'],
            ':support_phone' => $defaults['support_phone'],
            ':support_whatsapp' => $defaults['support_whatsapp'],
            ':support_email' => $defaults['support_email'],
            ':support_site' => $defaults['support_site'],
        ]);

        return $defaults;
    }

    return $row;
}

/**
 * Persist updates to the company profile.
 */
function company_save(array $data): void
{
    $pdo = db();
    company_ensure_table($pdo);

    $stmt = $pdo->prepare(
        'INSERT INTO company_profile (id, name, subtitle, logo_letter, support_phone, support_whatsapp, support_email, support_site)
         VALUES (1, :name, :subtitle, :logo_letter, :support_phone, :support_whatsapp, :support_email, :support_site)
         ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            subtitle = VALUES(subtitle),
            logo_letter = VALUES(logo_letter),
            support_phone = VALUES(support_phone),
            support_whatsapp = VALUES(support_whatsapp),
            support_email = VALUES(support_email),
            support_site = VALUES(support_site)'
    );

    $stmt->execute([
        ':name' => $data['name'] ?? 'FireSpot',
        ':subtitle' => $data['subtitle'] ?? '',
        ':logo_letter' => $data['logo_letter'] ?? 'F',
        ':support_phone' => $data['support_phone'] ?? null,
        ':support_whatsapp' => $data['support_whatsapp'] ?? null,
        ':support_email' => $data['support_email'] ?? null,
        ':support_site' => $data['support_site'] ?? null,
    ]);
}
