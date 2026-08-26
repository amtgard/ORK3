<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Regression cover for the officer-role rename.
 *
 * migrations/officer-position.sql rewrites ork_officer.role from the display name
 * to the canonical key for every existing row:
 *
 *     UPDATE ork_officer SET role='prime_minister' WHERE role='Prime Minister';
 *
 * Single-word roles survived that by accident -- the column is utf8mb4_unicode_ci,
 * so 'monarch' still matches 'Monarch' in SQL. Prime Minister did not, because the
 * canonical key swaps the space for an underscore, and PHP comparison is
 * case-sensitive so in PHP-land every role broke. Consumers that compared against a
 * literal therefore stopped matching the officers they were written for:
 * QualTest's test-administration gate, the kingdom officer directory's PM columns,
 * and the Player page's officer preload.
 *
 * These tests pin the normalization helpers those consumers now route through.
 */
final class OfficerRoleCanonicalTest extends TestCase
{
    /** Both stored forms must reduce to the same canonical key. */
    public function testCanonicalAcceptsEitherStoredForm(): void
    {
        foreach ([
            'Prime Minister' => 'prime_minister',
            'prime_minister' => 'prime_minister',
            'Monarch'        => 'monarch',
            'monarch'        => 'monarch',
            'Regent'         => 'regent',
            'GMR'            => 'gmr',
            'gmr'            => 'gmr',
            'Champion'       => 'champion',
        ] as $stored => $expected) {
            $this->assertSame(
                $expected,
                PermissionRegistry::CanonicalOfficerRole($stored),
                "'$stored' should canonicalize to '$expected'"
            );
        }
    }

    /** The exact pair that the ci collation could NOT paper over. */
    public function testPrimeMinisterIsTheCaseCollationCannotFix(): void
    {
        $this->assertSame(
            PermissionRegistry::CanonicalOfficerRole('Prime Minister'),
            PermissionRegistry::CanonicalOfficerRole('prime_minister'),
            'The pre- and post-migration forms of Prime Minister must be the same role'
        );
        // The literal comparison that used to be spread across the codebase.
        $this->assertNotSame('Prime Minister', 'prime_minister');
    }

    /** Anything user-facing needs the label back, not the raw stored value. */
    public function testLabelRendersHumanTextFromEitherForm(): void
    {
        $this->assertSame('Prime Minister', PermissionRegistry::OfficerRoleLabel('prime_minister'));
        $this->assertSame('Prime Minister', PermissionRegistry::OfficerRoleLabel('Prime Minister'));
        $this->assertSame('GMR', PermissionRegistry::OfficerRoleLabel('gmr'));
    }

    /** A non-crown position (custom office) is not a crown role and passes through. */
    public function testUnknownRoleIsNotCanonicalizedButStillRenders(): void
    {
        $this->assertNull(PermissionRegistry::CanonicalOfficerRole('herald'));
        $this->assertNull(PermissionRegistry::CanonicalOfficerRole(''));
        $this->assertSame('herald', PermissionRegistry::OfficerRoleLabel('herald'));
    }

    /** A SQL IN() list has to match rows written on either side of the migration. */
    public function testVariantsCoverBothStoredForms(): void
    {
        $variants = PermissionRegistry::OfficerRoleVariants(['Monarch', 'Regent', 'Prime Minister', 'GMR']);

        foreach (['Monarch', 'monarch', 'Regent', 'regent', 'Prime Minister', 'prime_minister', 'GMR', 'gmr'] as $form) {
            $this->assertContains($form, $variants, "IN() list must match the stored form '$form'");
        }
        $this->assertSame($variants, array_unique($variants), 'No duplicate values in the IN() list');
    }

    /** The RBAC role name and the canonical officer key are one vocabulary. */
    public function testRbacRoleMappingAcceptsEitherForm(): void
    {
        $this->assertSame('prime_minister', PermissionRegistry::OfficerRoleToRbacRole('Prime Minister'));
        $this->assertSame('prime_minister', PermissionRegistry::OfficerRoleToRbacRole('prime_minister'));
        $this->assertNull(PermissionRegistry::OfficerRoleToRbacRole('not_an_office'));
    }

    /** The four qualification-test permissions must exist and be kingdom-scoped. */
    public function testQualTestPermissionsAreRegistered(): void
    {
        foreach ([
            'kingdom.qualtest.config',
            'kingdom.qualtest.questions.edit',
            'kingdom.qualtest.publish',
            'kingdom.qualtest.results.view',
        ] as $key) {
            $this->assertTrue(PermissionRegistry::Exists($key), "$key must be registered");
            $def = PermissionRegistry::Get($key);
            $this->assertSame('kingdom', $def[2], "$key must be kingdom-scoped");
        }
    }

    /** Every capability the domain class advertises maps to a registered permission. */
    public function testEveryQualTestCapabilityMapsToARegisteredPermission(): void
    {
        $caps = [
            QualTest::CAP_CONFIG,
            QualTest::CAP_QUESTIONS,
            QualTest::CAP_PUBLISH,
            QualTest::CAP_RESULTS,
        ];
        $this->assertSame($caps, array_unique($caps), 'Capability constants must be distinct');

        // The model mirrors the vocabulary for controllers; the two must not drift.
        $this->assertSame(QualTest::CAP_CONFIG, Model_QualTest::CAP_CONFIG);
        $this->assertSame(QualTest::CAP_QUESTIONS, Model_QualTest::CAP_QUESTIONS);
        $this->assertSame(QualTest::CAP_PUBLISH, Model_QualTest::CAP_PUBLISH);
        $this->assertSame(QualTest::CAP_RESULTS, Model_QualTest::CAP_RESULTS);
    }
}
