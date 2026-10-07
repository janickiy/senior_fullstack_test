<?php

namespace App\Entity;

use App\DTO\Import\NormalizedOrderDto;
use App\Enum\OrderStatus;
use App\Repository\OrderRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: OrderRepository::class)]
#[ORM\Table(name: 'marketplace_order')]
#[ORM\UniqueConstraint(name: 'uniq_shop_marketplace', columns: ['shop_id', 'marketplace_id'])]
#[ORM\Index(name: 'idx_shop_status_created', columns: ['shop_id', 'status', 'created_at'])]
class MarketplaceOrder
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64, options: ['collation' => 'utf8mb4_bin'])]
    private string $shopId;

    #[ORM\Column(length: 255, options: ['collation' => 'utf8mb4_bin'])]
    private string $marketplaceId;

    #[ORM\Column(length: 20, enumType: OrderStatus::class)]
    private OrderStatus $status;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(length: 255)]
    private string $customerName;

    #[ORM\Column(length: 12)]
    private string $customerPhone;

    #[ORM\Column(length: 20)]
    private string $deliveryType;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $district;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $address;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deliveryStartsAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deliveryEndsAt;

    #[ORM\Column(type: Types::JSON)]
    private array $items;

    #[ORM\Column(type: Types::BIGINT)]
    private int $itemsTotalCents;

    #[ORM\Column(type: Types::BIGINT)]
    private int $marketplaceTotalCents;

    #[ORM\Column(type: Types::BIGINT)]
    private int $deliveryCostCents;

    #[ORM\Column(type: Types::BIGINT)]
    private int $grandTotalCents;

    #[ORM\Column]
    private bool $needsReview;

    public function __construct(string $shopId, NormalizedOrderDto $order)
    {
        $timezone = new \DateTimeZone('UTC');
        $this->shopId = $shopId;
        $this->marketplaceId = $order->marketplaceId;
        $this->status = OrderStatus::from($order->status);
        $this->createdAt = $order->createdAt->setTimezone($timezone);
        $this->customerName = $order->customerName;
        $this->customerPhone = $order->customerPhone;
        $this->deliveryType = $order->deliveryType;
        $this->district = $order->district;
        $this->address = $order->address;
        $this->deliveryStartsAt = $order->deliveryStartsAt?->setTimezone($timezone);
        $this->deliveryEndsAt = $order->deliveryEndsAt?->setTimezone($timezone);
        $this->items = $order->items;
        $this->itemsTotalCents = $order->itemsTotalCents;
        $this->marketplaceTotalCents = $order->marketplaceTotalCents;
        $this->deliveryCostCents = $order->deliveryCostCents;
        $this->grandTotalCents = $order->grandTotalCents;
        $this->needsReview = $order->needsReview;
    }

    public function applyImportedStatus(NormalizedOrderDto $order): bool
    {
        $status = OrderStatus::from($order->status);
        if ($this->status === $status || $this->status->isFinal()) {
            return false;
        }

        $this->status = $status;

        return true;
    }

    public function getId(): int
    {
        return $this->id ?? throw new \LogicException('The order has not been persisted yet.');
    }

    public function getMarketplaceId(): string
    {
        return $this->marketplaceId;
    }

    public function getStatus(): OrderStatus
    {
        return $this->status;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getCustomerName(): string
    {
        return $this->customerName;
    }

    public function getCustomerPhone(): string
    {
        return $this->customerPhone;
    }

    public function getDeliveryType(): string
    {
        return $this->deliveryType;
    }

    public function getDistrict(): ?string
    {
        return $this->district;
    }

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function getDeliveryStartsAt(): ?\DateTimeImmutable
    {
        return $this->deliveryStartsAt;
    }

    public function getDeliveryEndsAt(): ?\DateTimeImmutable
    {
        return $this->deliveryEndsAt;
    }

    public function getItems(): array
    {
        return $this->items;
    }

    public function getItemsTotalCents(): int
    {
        return $this->itemsTotalCents;
    }

    public function getMarketplaceTotalCents(): int
    {
        return $this->marketplaceTotalCents;
    }

    public function getDeliveryCostCents(): int
    {
        return $this->deliveryCostCents;
    }

    public function getGrandTotalCents(): int
    {
        return $this->grandTotalCents;
    }

    public function needsReview(): bool
    {
        return $this->needsReview;
    }
}
