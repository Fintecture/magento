<?php

/**
 *  2020 Fintecture SAS
 *
 *  @author Fintecture
 *  @copyright 2020 Fintecture SAS
 *  @license http://www.gnu.org/licenses/gpl-3.0.txt General Public License v3.0 (GPLv3)
 */

namespace Fintecture\Tests;

use Fintecture\Payment\Model\BankType;
use PHPUnit\Framework\TestCase;

class BankTypeTest extends TestCase
{
    public function testToOptionArray(): void
    {
        $bankType = new BankType();
        $toOptionArray = $bankType->toOptionArray();
        $this->assertIsArray($toOptionArray);
    }
}
