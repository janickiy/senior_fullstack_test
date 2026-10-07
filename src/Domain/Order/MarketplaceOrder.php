<?php

namespace App\Domain\Order;

class MarketplaceOrder
{
    private ?int $id = null;

    private string $shopId;

    private string $marketplaceId;

    private OrderStatus $status;

    private \DateTimeImmutable $createdAt;

    private string $customerName;

    private string $customerPhone;

    private string $deliveryType;

    private ?string $district;

    private ?string $address;

    private ?\DateTimeImmutable $deliveryStartsAt;

    private ?\DateTimeImmutable $deliveryEndsAt;

    private array $items;

    private int $itemsTotalCents;

    private int $marketplaceTotalCents;

    private int $deliveryCostCents;

    private int $grandTotalCents;

    private bool $needsReview;

    public function __construct(string $shopId, OrderData $order)
    {
        $timezone = new \DateTimeZone('UTC');
        $this->shopId = $shopId;
        $this->marketplaceId = $order->marketplaceId;
        $this->status = $order->status;
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

    public function applyImportedStatus(OrderStatus $status): bool
    {
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
