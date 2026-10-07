<?php

namespace App\Tests\Service;

use App\Exception\InvalidOrderException;
use App\Service\Import\DeliveryCalculator;
use App\Service\Import\OrderNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class OrderNormalizerTest extends KernelTestCase
{
    private OrderNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new OrderNormalizer(static::getContainer()->get(ValidatorInterface::class), new DeliveryCalculator());
    }

    public function testNormalizesValidOrderAndCalculatesDelivery(): void
    {
        $order = $this->normalizer->normalize(self::validOrder());

        self::assertSame('MP-1001', $order->marketplaceId);
        self::assertSame('new', $order->status);
        self::assertSame('Анна Лебедева', $order->customerName);
        self::assertSame('+79001234567', $order->customerPhone);
        self::assertSame('2026-10-10T14:00:00+03:00', $order->deliveryStartsAt->format(\DateTimeInterface::RFC3339));
        self::assertSame('2026-10-10T16:00:00+03:00', $order->deliveryEndsAt->format(\DateTimeInterface::RFC3339));
        self::assertSame(320_000, $order->itemsTotalCents);
        self::assertSame(320_000, $order->marketplaceTotalCents);
        self::assertSame(30_000, $order->deliveryCostCents);
        self::assertSame(350_000, $order->grandTotalCents);
        self::assertFalse($order->needsReview);
    }

    #[DataProvider('statuses')]
    public function testMarketplaceStatusMapping(string $input, string $expected): void
    {
        $raw = self::validOrder();
        $raw['status'] = $input;

        self::assertSame($expected, $this->normalizer->normalize($raw)->status);
    }

    public static function statuses(): iterable
    {
        yield ['NEW', 'new'];
        yield ['ACCEPTED', 'accepted'];
        yield ['IN_DELIVERY', 'delivering'];
        yield ['DONE', 'delivered'];
        yield ['CANCELED', 'cancelled'];
    }

    #[DataProvider('phones')]
    public function testPhoneNormalization(string $input): void
    {
        $raw = self::validOrder();
        $raw['customer']['phone'] = $input;

        self::assertSame('+79001234567', $this->normalizer->normalize($raw)->customerPhone);
    }

    public static function phones(): iterable
    {
        yield ['+7 (900) 123-45-67'];
        yield ['89001234567'];
        yield [' 8 900 123 45 67 '];
        yield ['79001234567'];
        yield ["+7\u{00A0}(900) 123-45-67"];
    }

    public function testUsesItemSumAndMarksMismatchedTotalForReview(): void
    {
        $raw = self::validOrder();
        $raw['delivery']['district'] = 'suburb';
        $raw['items'] = [['qty' => 2, 'price' => 1800]];
        $raw['total'] = 3000;
        $order = $this->normalizer->normalize($raw);

        self::assertTrue($order->needsReview);
        self::assertSame(360_000, $order->itemsTotalCents);
        self::assertSame(300_000, $order->marketplaceTotalCents);
        self::assertSame(70_000, $order->deliveryCostCents);
        self::assertSame(430_000, $order->grandTotalCents);
        self::assertSame('', $order->items[0]['sku']);
        self::assertSame('', $order->items[0]['name']);
    }

    public function testMoneyIsCalculatedInExactIntegerKopecks(): void
    {
        $raw = self::validOrder();
        $raw['delivery'] = ['type' => 'pickup'];
        $raw['items'] = [
            ['qty' => 3, 'price' => 0.1],
            ['qty' => 2, 'price' => '0.29'],
            ['qty' => 1, 'price' => '1.02'],
        ];
        $raw['total'] = '1.90';
        $order = $this->normalizer->normalize($raw);

        self::assertSame(190, $order->itemsTotalCents);
        self::assertSame(190, $order->grandTotalCents);
        self::assertFalse($order->needsReview);
        self::assertSame(29, $order->items[1]['unit_price_cents']);
    }

    public function testPickupNeedsNoDeliveryAddressOrInterval(): void
    {
        $raw = self::validOrder();
        $raw['delivery'] = ['type' => 'pickup'];
        $order = $this->normalizer->normalize($raw);

        self::assertSame(0, $order->deliveryCostCents);
        self::assertNull($order->district);
        self::assertNull($order->address);
        self::assertNull($order->deliveryStartsAt);
        self::assertNull($order->deliveryEndsAt);
    }

    public function testDeliveryIntervalUsesMoscowAndCreationOffsetUsesActualInstant(): void
    {
        $raw = self::validOrder();
        $raw['created_at'] = '2026-10-10T10:30:00Z';
        $order = $this->normalizer->normalize($raw);

        self::assertSame('2026-10-10T14:00:00+03:00', $order->deliveryStartsAt->format(\DateTimeInterface::RFC3339));
        self::assertSame(50_000, $order->deliveryCostCents);
    }

    public function testIntegerMarketplaceIdentifierIsNormalizedToString(): void
    {
        $raw = self::validOrder();
        $raw['id'] = 1001;

        self::assertSame('1001', $this->normalizer->normalize($raw)->marketplaceId);
    }

    #[DataProvider('invalidFields')]
    public function testRejectsInvalidFieldsWithoutTypeErrors(array $path, mixed $value, string $expectedCode): void
    {
        $raw = self::validOrder();
        $target = &$raw;

        foreach (\array_slice($path, 0, -1) as $part) {
            $target = &$target[$part];
        }

        $target[$path[\count($path) - 1]] = $value;

        try {
            $this->normalizer->normalize($raw);
            self::fail('Invalid order must be rejected.');
        } catch (InvalidOrderException $exception) {
            self::assertSame($expectedCode, $exception->reasonCode);
            self::assertNotSame('', $exception->getMessage());
        }
    }

    public static function invalidFields(): iterable
    {
        yield 'missing id' => [['id'], null, 'invalid_id'];
        yield 'blank id' => [['id'], '  ', 'invalid_id'];
        yield 'boolean id' => [['id'], true, 'invalid_id'];
        yield 'object id' => [['id'], ['unexpected' => 'object'], 'invalid_id'];
        yield 'unknown status' => [['status'], 'PACKING', 'unknown_status'];
        yield 'wrong status type' => [['status'], ['NEW'], 'unknown_status'];
        yield 'creation time missing offset' => [['created_at'], '2026-10-10T11:00:00', 'invalid_created_at'];
        yield 'creation time invalid date' => [['created_at'], '2026-02-30T11:00:00+03:00', 'invalid_created_at'];
        yield 'creation time invalid time' => [['created_at'], '2026-10-10T25:00:00+03:00', 'invalid_created_at'];
        yield 'creation time invalid offset' => [['created_at'], '2026-10-10T11:00:00+03:70', 'invalid_created_at'];
        yield 'blank customer name' => [['customer', 'name'], '   ', 'invalid_customer_name'];
        yield 'object customer name' => [['customer', 'name'], ['Анна'], 'invalid_customer_name'];
        yield 'short phone' => [['customer', 'phone'], '123', 'invalid_phone'];
        yield 'foreign phone' => [['customer', 'phone'], '+19001234567', 'invalid_phone'];
        yield 'letters in phone' => [['customer', 'phone'], '+79001234567abc', 'invalid_phone'];
        yield 'numeric phone' => [['customer', 'phone'], 89001234567, 'invalid_phone'];
        yield 'two plus signs in phone' => [['customer', 'phone'], '++79001234567', 'invalid_phone'];
        yield 'unsupported delivery type' => [['delivery', 'type'], 'courier', 'invalid_delivery_type'];
        yield 'missing district' => [['delivery', 'district'], '', 'invalid_delivery'];
        yield 'unknown district' => [['delivery', 'district'], 'zarechye', 'unknown_district'];
        yield 'missing delivery address' => [['delivery', 'address'], '', 'invalid_delivery'];
        yield 'invalid delivery date' => [['delivery', 'date'], '2026-02-30', 'invalid_delivery_interval'];
        yield 'invalid interval format' => [['delivery', 'time_from'], '9:00', 'invalid_delivery_interval'];
        yield 'equal interval bounds' => [['delivery', 'time_to'], '14:00', 'invalid_delivery_interval'];
        yield 'reversed interval bounds' => [['delivery', 'time_to'], '12:00', 'invalid_delivery_interval'];
        yield 'no items' => [['items'], [], 'invalid_items'];
        yield 'wrong item list type' => [['items'], 'invalid', 'invalid_items'];
        yield 'associative item list' => [['items'], ['sku' => ['qty' => 1, 'price' => 1]], 'invalid_items'];
        yield 'non-object item' => [['items'], ['invalid'], 'invalid_items'];
        yield 'zero quantity' => [['items', 0, 'qty'], 0, 'invalid_quantity'];
        yield 'negative quantity' => [['items', 0, 'qty'], -1, 'invalid_quantity'];
        yield 'fractional quantity' => [['items', 0, 'qty'], 1.5, 'invalid_quantity'];
        yield 'string quantity' => [['items', 0, 'qty'], '1', 'invalid_quantity'];
        yield 'price missing' => [['items', 0, 'price'], null, 'invalid_price'];
        yield 'negative price' => [['items', 0, 'price'], -1, 'invalid_price'];
        yield 'price with fractions of kopeck' => [['items', 0, 'price'], '1.001', 'invalid_price'];
        yield 'float with fractions of kopeck' => [['items', 0, 'price'], 1.001, 'invalid_price'];
        yield 'price exponent string' => [['items', 0, 'price'], '1e2', 'invalid_price'];
        yield 'boolean price' => [['items', 0, 'price'], true, 'invalid_price'];
        yield 'price object' => [['items', 0, 'price'], ['value' => 1], 'invalid_price'];
        yield 'overflowing price' => [['items', 0, 'price'], (string) \PHP_INT_MAX, 'amount_too_large'];
        yield 'overflowing multiplication' => [['items', 0, 'qty'], \PHP_INT_MAX, 'amount_too_large'];
        yield 'missing total' => [['total'], null, 'invalid_total'];
        yield 'total with fractions of kopeck' => [['total'], '1.001', 'invalid_total'];
    }

    public function testNonObjectOrderIsRejected(): void
    {
        $this->expectException(InvalidOrderException::class);
        $this->normalizer->normalize('invalid');
    }

    /** @return array<string, mixed> */
    private static function validOrder(): array
    {
        return [
            'id' => ' MP-1001 ',
            'status' => 'NEW',
            'created_at' => '2026-10-10T11:00:00+03:00',
            'customer' => ['name' => ' Анна Лебедева ', 'phone' => '+7 (900) 123-45-67'],
            'delivery' => [
                'type' => 'delivery',
                'district' => 'centre',
                'address' => 'ул. Большая Садовая, 10, кв. 4',
                'date' => '2026-10-10',
                'time_from' => '14:00',
                'time_to' => '16:00',
            ],
            'items' => [['sku' => 'B-101', 'name' => 'Букет «Нежность»', 'qty' => 1, 'price' => 3200]],
            'total' => 3200,
        ];
    }
}
