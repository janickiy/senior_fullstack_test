import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  inject,
  OnInit,
  signal,
} from '@angular/core';
import { ImportOutcome, OrderStatus, OrderView } from './orders.models';
import { OrdersStore } from './orders.store';

@Component({
  selector: 'app-root',
  templateUrl: './app.html',
  styleUrl: './app.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class App implements OnInit {
  readonly store = inject(OrdersStore);
  readonly reportExpanded = signal(true);
  readonly statuses: { value: OrderStatus | ''; label: string }[] = [
    { value: '', label: 'Все заказы' },
    { value: 'new', label: 'Новые' },
    { value: 'accepted', label: 'Принятые' },
    { value: 'delivering', label: 'В доставке' },
    { value: 'delivered', label: 'Доставленные' },
    { value: 'cancelled', label: 'Отменённые' },
  ];
  readonly outcomes: { value: ImportOutcome; label: string }[] = [
    { value: 'created', label: 'Создано' },
    { value: 'updated', label: 'Обновлено' },
    { value: 'unchanged', label: 'Без изменений' },
    { value: 'duplicate', label: 'Дубли' },
    { value: 'rejected', label: 'Отклонено' },
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
  private readonly deliveryDayFormatter = new Intl.DateTimeFormat('ru-RU', {
    timeZone: 'Europe/Moscow',
    day: 'numeric',
    month: 'long',
  });
  private readonly createdFormatter = new Intl.DateTimeFormat('ru-RU', {
    timeZone: 'Europe/Moscow',
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
  private readonly moneyFormatter = new Intl.NumberFormat('ru-RU', {
    style: 'currency',
    currency: 'RUB',
    minimumFractionDigits: 0,
    maximumFractionDigits: 2,
  });
  private readonly dateFormatter = new Intl.DateTimeFormat('ru-RU', {
    timeZone: 'Europe/Moscow',
    day: 'numeric',
    month: 'short',
    year: 'numeric',
  });
  private readonly timeFormatter = new Intl.DateTimeFormat('ru-RU', {
    timeZone: 'Europe/Moscow',
    hour: '2-digit',
    minute: '2-digit',
  });

  ngOnInit(): void {
    const requestedShop = new URLSearchParams(window.location.search).get('shop');
    if (
      requestedShop &&
      /^[A-Za-z0-9_-]{1,64}$/.test(requestedShop) &&
      requestedShop !== this.store.shopId()
    ) {
      this.store.setShop(requestedShop);
    } else {
      this.store.refresh();
    }
  }

  skipHref(): string {
    return `${window.location.pathname}${window.location.search}#orders`;
  }

  importExcel(event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    input.value = '';
    if (file) {
      this.reportExpanded.set(true);
      this.store.importExcel(file);
    }
  }

  money(value: number): string {
    return this.moneyFormatter.format(value);
  }

  statusLabel(status: OrderStatus): string {
    return {
      new: 'Новый',
      accepted: 'Принят',
      delivering: 'В доставке',
      delivered: 'Доставлен',
      cancelled: 'Отменён',
    }[status];
  }

  outcomeLabel(outcome: ImportOutcome): string {
    return {
      created: 'Создан',
      updated: 'Обновлён',
      unchanged: 'Без изменений',
      duplicate: 'Дубль',
      rejected: 'Отклонён',
    }[outcome];
  }

  deliveryInterval(order: OrderView): string {
    if (!order.delivery.starts_at || !order.delivery.ends_at) return 'В удобное время';
    const start = new Date(order.delivery.starts_at);
    return `${this.dateFormatter.format(start)} · ${this.timeFormatter.format(start)}–${this.timeFormatter.format(new Date(order.delivery.ends_at))}`;
  }

  createdDate(order: OrderView): string {
    return this.createdFormatter.format(new Date(order.created_at));
  }

  deliveryDate(order: OrderView): string {
    if (order.delivery.type === 'pickup') return 'Самовывоз';
    return order.delivery.starts_at
      ? this.deliveryDayFormatter.format(new Date(order.delivery.starts_at))
      : 'Дата не указана';
  }

  deliveryWindow(order: OrderView): string {
    if (order.delivery.type === 'pickup') return 'Из магазина';
    const interval =
      order.delivery.starts_at && order.delivery.ends_at
        ? `${this.timeFormatter.format(new Date(order.delivery.starts_at))}–${this.timeFormatter.format(new Date(order.delivery.ends_at))}`
        : 'Интервал не указан';
    const district = this.districtLabel(order.delivery.district);
    return district ? `${interval} · ${district}` : interval;
  }

  districtLabel(district: string | null): string {
    const labels: Record<string, string> = {
      centre: 'Центр',
      north: 'Северный',
      south: 'Южный',
      suburb: 'Пригород',
    };
    return district ? (labels[district] ?? district) : '';
  }

  isOrderExpanded(id: number): boolean {
    return this.expandedOrders().has(id);
  }

  toggleOrder(id: number): void {
    const expanded = new Set(this.expandedOrders());
    if (expanded.has(id)) expanded.delete(id);
    else expanded.add(id);
    this.expandedOrders.set(expanded);
  }

  fieldLabel(field: string): string {
    const labels: Record<string, string> = {
      id: 'Номер заказа',
      status: 'Статус',
      created_at: 'Дата создания',
      'customer.name': 'Имя покупателя',
      'customer.phone': 'Телефон',
      'delivery.type': 'Получение',
      'delivery.district': 'Район',
      'delivery.address': 'Адрес',
      'delivery.date': 'Дата доставки',
      'delivery.time_from': 'Начало интервала',
      'delivery.time_to': 'Конец интервала',
      items: 'Позиции заказа',
      total: 'Сумма заказа',
    };
    if (field.startsWith('items.')) return 'Позиция заказа';
    return labels[field] ?? 'Данные заказа';
  }
}
