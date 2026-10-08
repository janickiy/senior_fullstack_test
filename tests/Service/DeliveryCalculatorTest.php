<?php

namespace App\Tests\Service;

use App\Exception\InvalidOrderException;
use App\Service\Import\DeliveryCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DeliveryCalculatorTest extends KernelTestCase
{
    #[DataProvider('tariffs')]
    public function testTariffsAndUrgency(string $type, ?string $district, int $total, string $start, int $expected): void
    {
        $calculator = new DeliveryCalculator();
        $cost = $calculator->calculate(
            $type,
            $district,
            $total,
            new \DateTimeImmutable('2026-10-10T11:00:00+03:00'),
            new \DateTimeImmutable($start),
        );

        self::assertSame($expected, $cost);
    }

    public static function tariffs(): iterable
    {
        yield 'pickup ignores delivery tariff and urgency' => ['pickup', null, 100, '2026-10-10T11:30:00+03:00', 0];
        yield 'centre below free threshold' => ['delivery', 'centre', 499_999, '2026-10-10T14:00:00+03:00', 30_000];
        yield 'centre at free threshold' => ['delivery', 'centre', 500_000, '2026-10-10T14:00:00+03:00', 0];
        yield 'centre above free threshold' => ['delivery', 'centre', 500_001, '2026-10-10T14:00:00+03:00', 0];
        yield 'north below free threshold' => ['delivery', 'north', 699_999, '2026-10-10T14:00:00+03:00', 40_000];
        yield 'north at free threshold' => ['delivery', 'north', 700_000, '2026-10-10T14:00:00+03:00', 0];
        yield 'south below free threshold' => ['delivery', 'south', 699_999, '2026-10-10T14:00:00+03:00', 40_000];
        yield 'south at free threshold' => ['delivery', 'south', 700_000, '2026-10-10T14:00:00+03:00', 0];
        yield 'suburb never becomes free' => ['delivery', 'suburb', 10_000_000, '2026-10-10T14:00:00+03:00', 70_000];
        yield 'urgent delivery' => ['delivery', 'north', 500_000, '2026-10-10T12:00:00+03:00', 60_000];
        yield 'one second before urgency boundary' => ['delivery', 'centre', 320_000, '2026-10-10T12:59:59+03:00', 50_000];
        yield 'exactly two hours is not urgent' => ['delivery', 'centre', 320_000, '2026-10-10T13:00:00+03:00', 30_000];
        yield 'free base still has urgent surcharge' => ['delivery', 'centre', 500_000, '2026-10-10T12:00:00+03:00', 20_000];
        yield 'urgency applies to suburb' => ['delivery', 'suburb', 10_000_000, '2026-10-10T12:00:00+03:00', 90_000];
        yield 'past interval follows literal less-than rule' => ['delivery', 'south', 320_000, '2026-10-10T10:00:00+03:00', 60_000];
        yield 'timestamps with different offsets' => ['delivery', 'north', 500_000, '2026-10-10T09:00:00Z', 60_000];
        yield 'date contributes to urgency' => ['delivery', 'south', 280_000, '2026-10-11T12:00:00+03:00', 40_000];
    }

    public function testUnknownDistrictHasRequiredReasonCode(): void
    {
        try {
            (new DeliveryCalculator())->calculate(
                'delivery',
                'zarechye',
                290_000,
                new \DateTimeImmutable('2026-10-09T13:00:00+03:00'),
                new \DateTimeImmutable('2026-10-12T11:00:00+03:00'),
            );
            self::fail('Unknown district must be rejected.');
        } catch (InvalidOrderException $exception) {
            self::assertSame('unknown_district', $exception->reasonCode);
            self::assertSame('delivery.district', $exception->details[0]['field']);
        }
    }
}
