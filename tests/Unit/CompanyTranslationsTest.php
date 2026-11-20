<?php

declare(strict_types=1);

/*
 * This file is part of the Thelia package.
 * http://www.thelia.net
 *
 * (c) OpenStudio <info@thelia.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FlexyBundle\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The sentences of the company pages are written in English in the templates and carried by the
 * catalogs: one missing from a catalog reaches the buyer untranslated. English answers itself and
 * French is what the shop of a French buyer reads.
 */
final class CompanyTranslationsTest extends TestCase
{
    private const array LOCALES = ['en_US', 'fr_FR'];

    /** Words French writes as English does. */
    private const array SAME_IN_FRENCH = ['Invitation'];

    private const array KEYS = [
        'Buyers',
        'My company',
        'Invite a buyer',
        'The person receives a link, valid for seven days, to create the password of their account or to sign in.',
        'E-mail address',
        'Role',
        'Send the invitation',
        'The invited buyer starts with your own limits, and cannot be given higher ones.',
        'Are you sure you want to remove this buyer from the company ?',
        'Invitation',
        'Join %company%',
        'This invitation is invalid or has expired',
        'Ask the administrator of your company to send you a new invitation.',
        'Back to the home page',
        'You have been invited to join this company with the address %email%.',
        'Accept the invitation',
        'This invitation was sent to another e-mail address than the one of the account you are signed in with. Sign out, then open the link again.',
        'An account already exists for this address: sign in to accept the invitation.',
        'Create my account and join',
        'Company name',
        'E-mail address that receives a copy of the order e-mails',
        'you',
        'Limit per order',
        'Limit per period',
        'Period',
        'No period',
        'Week',
        'Month',
        'Year',
        'Remove from the company',
        'This buyer holds rights beyond yours: only a buyer with the same rights can change them.',
        'Your company has no buyer yet',
        'The details of your company have been updated',
        'The buyer has been updated',
        'The buyer has left your company',
        'The invitation has been sent',
        'You have joined the company',
        'Please check what you typed: the change was not saved',
        'The registration number or the VAT number is not valid: the change was not saved',
        'Please type a valid e-mail address and choose a role',
        'The registration number and the VAT number can no longer be changed here: orders of your company were placed without VAT on them. Please contact the shop.',
        'A company keeps at least one administrator: give the role to another buyer first',
        'You cannot give a role or limits beyond your own, nor change a buyer who holds more rights than you',
        'Too many invitations have been sent, please try again later',
        'This person already belongs to a company',
        'The invitation could not be sent, please try again later',
        'This buyer does not exist any more',
        'Company details',
        'Orders of the company',
        'Your company account is suspended: orders cannot be placed until it is reactivated.',
        'My personal addresses',
        'Addresses of my company',
        'Placed by',
        'Shared with the company',
        'Share this address with my company',
    ];

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function sentences(): iterable
    {
        foreach (self::LOCALES as $locale) {
            foreach (self::KEYS as $key) {
                yield $locale.' / '.$key => [$locale, $key];
            }
        }
    }

    #[DataProvider('sentences')]
    public function testTheCatalogCarriesTheSentence(string $locale, string $key): void
    {
        $catalog = Yaml::parseFile(\dirname(__DIR__, 2).'/translations/messages.'.$locale.'.yaml');

        self::assertArrayHasKey($key, $catalog, $locale);
        self::assertNotSame('', trim((string) $catalog[$key]));

        if ('fr_FR' === $locale && !\in_array($key, self::SAME_IN_FRENCH, true)) {
            self::assertNotSame($key, $catalog[$key], 'The French catalog does not repeat the English sentence.');
        }
    }
}
