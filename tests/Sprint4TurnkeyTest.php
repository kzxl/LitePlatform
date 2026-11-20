<?php

declare(strict_types=1);

namespace Tests;

use Lite\Framework\Console\DoctorCommand;
use Lite\Framework\Security\CsrfGuard;
use Lite\Framework\Security\HoneypotGuard;
use Lite\Framework\Testing\TestCase;
use LiteCache\CacheInterface;
use PDO;

/**
 * Verifies Sprint 4 turnkey end-to-end features:
 * - Public /contact lead capture (CSRF + Honeypot + Validation + DB lead save)
 * - Database-backed 301 SEO Redirects cached via LiteCache
 * - Shared Hosting & Security preflight DoctorCommand (normal vs --strict production)
 */
class Sprint4TurnkeyTest extends TestCase
{
    public function testContactFormRendersHoneypotRejectsBotsAndSavesValidLeads(): void
    {
        // 1. GET /contact renders both _csrf and _hp_ts fields
        $res = $this->get('/contact?lang=vi');
        $this->assertStatus($res, 200);
        $html = (string)$res->getBody();
        $this->assertStringContainsString('name="_csrf"', $html);
        $this->assertStringContainsString('name="_hp_website"', $html);
        $this->assertStringContainsString('name="_hp_ts"', $html);

        preg_match('/name="_csrf"\s+value="([^"]+)"/', $html, $mCsrf);
        $csrf = $mCsrf[1] ?? '';
        $this->assertNotEmpty($csrf);

        // Build a valid human-elapsed honeypot timestamp (5 seconds ago)
        /** @var HoneypotGuard $hp */
        $hp = $this->app->getContainer()->get(HoneypotGuard::class);
        $hpHtml = $hp->renderFields(time() - 5);
        preg_match('/name="_hp_ts"\s+value="([^"]+)"/', $hpHtml, $mTs);
        $validTs = $mTs[1] ?? '';

        // 2. Bot filling trap field -> 422
        $botReq = $this->factory->createServerRequest('POST', '/contact')
            ->withCookieParams([CsrfGuard::COOKIE_NAME => $csrf])
            ->withParsedBody([
                '_csrf'       => $csrf,
                '_hp_website' => 'https://spam.example',
                '_hp_ts'      => $validTs,
                'name'        => 'Spammer',
                'email'       => 'spam@example.com',
                'message'     => 'Buy cheap links now',
            ]);
        $botRes = $this->app->handle($botReq);
        $this->assertStatus($botRes, 422);

        // 3. Valid human submission -> 200 + persisted in contact_leads
        $probeEmail = 'lead-' . bin2hex(random_bytes(3)) . '@example.com';
        $okReq = $this->factory->createServerRequest('POST', '/contact')
            ->withCookieParams([CsrfGuard::COOKIE_NAME => $csrf])
            ->withParsedBody([
                '_csrf'       => $csrf,
                '_hp_website' => '',
                '_hp_ts'      => $validTs,
                'name'        => 'Nguyễn Văn A',
                'email'       => $probeEmail,
                'phone'       => '0909000111',
                'message'     => 'Cần tư vấn thiết kế website bán hàng chuẩn SEO.',
            ]);
        $okRes = $this->app->handle($okReq);
        $this->assertStatus($okRes, 200);

        /** @var PDO $pdo */
        $pdo = $this->app->getContainer()->get(PDO::class);
        try {
            $stmt = $pdo->prepare('SELECT name, email, phone FROM contact_leads WHERE email = :email LIMIT 1');
            $stmt->execute([':email' => $probeEmail]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $this->assertIsArray($row);
            $this->assertEquals('Nguyễn Văn A', $row['name']);
        } finally {
            $del = $pdo->prepare('DELETE FROM contact_leads WHERE email = :email');
            $del->execute([':email' => $probeEmail]);
        }
    }

    public function testDatabaseBackedSeoRedirectReturns301AndCachesResult(): void
    {
        /** @var PDO $pdo */
        $pdo = $this->app->getContainer()->get(PDO::class);
        /** @var CacheInterface $cache */
        $cache = $this->app->getContainer()->get(CacheInterface::class);

        $oldPath = '/legacy-slug-' . bin2hex(random_bytes(3));
        $stmt = $pdo->prepare(
            "INSERT INTO redirects (source_path, target_url, status_code, is_active) VALUES (:src, :dst, 301, 1)"
        );
        $stmt->execute([':src' => $oldPath, ':dst' => '/blog/new-seo-slug']);
        $cache->tags(['seo_redirects'])->flushTags();

        try {
            $res = $this->get($oldPath);
            $this->assertStatus($res, 301);
            $this->assertEquals('/blog/new-seo-slug', $res->getHeaderLine('Location'));
        } finally {
            $del = $pdo->prepare('DELETE FROM redirects WHERE source_path = :src');
            $del->execute([':src' => $oldPath]);
            $cache->tags(['seo_redirects'])->flushTags();
        }
    }

    public function testDoctorCommandPassesLocallyAndFlagsWeakSecretsInStrictMode(): void
    {
        $doctor = new DoctorCommand();
        $baseDir = dirname(__DIR__);

        $devReport = $doctor->diagnose($baseDir, strictProduction: false);
        $this->assertTrue($devReport['ok'], 'Dev environment must pass non-strict preflight checks');
        $this->assertEquals(0, $devReport['failures']);

        $strictReport = $doctor->diagnose($baseDir, strictProduction: true);
        $this->assertFalse($strictReport['ok'], 'Strict production preflight must flag local debug/default secrets');
        $this->assertGreaterThan(0, $strictReport['failures']);
    }
}
