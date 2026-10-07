import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  inject,
  signal,
} from '@angular/core';
import { OrderStatus } from '../../domain/orders.models';
import { OrdersStore } from '../../application/orders.store';
import * as format from '../order-format';

@Component({
  selector: 'app-orders-table',
  templateUrl: './orders-table.html',
  styleUrl: './orders-table.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class OrdersTable {
  readonly store = inject(OrdersStore);
  readonly format = format;
  readonly statuses: { value: OrderStatus | ''; label: string }[] = [
    { value: '', label: 'Все заказы' },
    { value: 'new', label: 'Новые' },
    { value: 'accepted', label: 'Принятые' },
    { value: 'delivering', label: 'В доставке' },
    { value: 'delivered', label: 'Доставленные' },
    { value: 'cancelled', label: 'Отменённые' },
  ];
  readonly expandedOrders = signal<ReadonlySet<number>>(new Set());
  readonly selectedStatusLabel = computed(
    () =>
      this.statuses.find((status) => status.value === this.store.status())?.label ?? 'Все заказы',
  );
  readonly reviewCount = computed(
    () => this.store.orders().filter((order) => order.needs_review).length,
  );
  readonly firstOrderIndex = computed(() =>
    this.store.orders().length ? (this.store.page() - 1) * this.store.pagination().limit + 1 : 0,
  );
  readonly lastOrderIndex = computed(() =>
    this.store.orders().length ? this.firstOrderIndex() + this.store.orders().length - 1 : 0,
  );
  private readonly resetExpandedOrders = effect(() => {
    this.store.shopId();
    this.store.status();
    this.store.page();
    this.expandedOrders.set(new Set());
  });
  isOrderExpanded(id: number): boolean {
    return this.expandedOrders().has(id);
  }

  toggleOrder(id: number): void {
    const expanded = new Set(this.expandedOrders());
    if (expanded.has(id)) expanded.delete(id);
    else expanded.add(id);
    this.expandedOrders.set(expanded);
  }
}
