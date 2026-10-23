<?php

namespace MyPinacle\Tracking\Tests;

use MyPinacle\Tracking\Graph;
use MyPinacle\Tracking\Support\Country;
use MyPinacle\Tracking\Support\EventIds;
use MyPinacle\Tracking\Support\Hashing;

final class SupportTest extends TestCase
{
    public function test_graph_version_is_single_source(): void
    {
        $this->assertSame('v26.0', Graph::VERSION);
        $this->assertSame('https://graph.facebook.com/v26.0/123/events', Graph::url('/123/events'));
        $this->assertSame('https://www.facebook.com/v26.0/dialog/oauth', Graph::webUrl('dialog/oauth'));
    }

    public function test_hashing_normalisation(): void
    {
        $this->assertSame('a@b.com', Hashing::email('  A@B.com '));
        $this->assertNull(Hashing::email('guest123@nomail.com'));
        $this->assertSame('8801711000000', Hashing::metaPhone('01711-000000'));
        $this->assertSame('8801711000000', Hashing::metaPhone('+880 1711 000000'));
        $this->assertSame('8801711000000', Hashing::metaPhone('1711000000'));
        $this->assertSame('+8801711000000', Hashing::e164('01711000000'));
        $this->assertNull(Hashing::metaPhone(''));
        $this->assertSame('newyork', Hashing::city(' New York! '));
        $this->assertSame('ঢাকা', Hashing::city('ঢাকা'));
        $this->assertSame('rahim', Hashing::name(' Rahim '));
        $ec = Hashing::enhancedConversions('X@Y.com', '01711000000');
        $this->assertSame(hash('sha256', 'x@y.com'), $ec['em_sha256']);
        $this->assertSame(hash('sha256', '+8801711000000'), $ec['ph_sha256']);
    }

    public function test_country_normalises_to_lowercase_iso2_or_null(): void
    {
        foreach (['BD', 'bd', 'BGD', 'Bangladesh', ' bangladesh ', 'বাংলাদেশ'] as $v) {
            $this->assertSame('bd', Country::normalize($v), $v);
        }
        $this->assertSame('gb', Country::normalize('United Kingdom'));
        $this->assertSame('gb', Country::normalize('UK'));
        $this->assertSame('us', Country::normalize('USA'));
        $this->assertSame('in', Country::normalize('India'));
        $this->assertNull(Country::normalize('Narnia'));
        $this->assertNull(Country::normalize('xx'));
        $this->assertNull(Country::normalize(null));
        $this->assertSame('in', Country::resolve(null, '', 'India', 'Bangladesh'));
        $this->assertSame('bd', Country::resolve('Narnia', 'BD'));
        $this->assertNull(Country::resolve(null, 'Narnia'));
    }

    public function test_event_ids_are_signed_and_nonce_ids_are_stable(): void
    {
        $ids = new EventIds('secret');
        $id = $ids->make('atc');
        $this->assertMatchesRegularExpression('/^atc_[a-z0-9]+_[a-f0-9]{10}$/', $id);
        $this->assertTrue($ids->verify($id));
        $this->assertTrue($ids->verify($id, 'atc'));
        $this->assertFalse($ids->verify($id, 'vc'));
        $this->assertFalse($ids->verify(substr($id, 0, -1) . (substr($id, -1) === 'a' ? 'b' : 'a')));
        $this->assertFalse((new EventIds('other'))->verify($id));
        $this->assertFalse($ids->verify('atc_madeupbybrowser_0123456789'));
        $this->assertNotSame($id, $ids->make('atc'));

        $a = $ids->forNonce('ic', 'n-1', 'session-a');
        $this->assertSame($a, $ids->forNonce('ic', 'n-1', 'session-a'));
        $this->assertNotSame($a, $ids->forNonce('ic', 'n-1', 'session-b'));
        $this->assertNotSame($a, $ids->forNonce('ic', 'n-2', 'session-a'));
        $this->assertTrue($ids->verify($a, 'ic'));
    }
}
