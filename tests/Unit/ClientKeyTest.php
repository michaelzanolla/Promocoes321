<?php

namespace Tests\Unit;

use App\Support\ClientKey;
use PHPUnit\Framework\TestCase;

class ClientKeyTest extends TestCase
{
    public function test_ipv4_is_kept_and_ipv6_is_grouped_by_its_64_block(): void
    {
        $this->assertSame('203.0.113.7', ClientKey::of('203.0.113.7'));
        $this->assertSame('203.0.113.7', ClientKey::of('::ffff:203.0.113.7'));
        $this->assertSame('2001:db8:1:2::/64', ClientKey::of('2001:db8:1:2:abcd::1'));
        $this->assertSame(ClientKey::of('2001:db8:1:2::1'), ClientKey::of('2001:db8:1:2:ffff:ffff:ffff:ffff'));
        $this->assertNotSame(ClientKey::of('2001:db8:1:2::1'), ClientKey::of('2001:db8:1:3::1'));
        $this->assertSame('', ClientKey::of(null));
        $this->assertSame('não-é-ip', ClientKey::of('não-é-ip'));
    }
}
