import { OrderStatus, OrderView } from '../domain/orders.models';

const deliveryDayFormatter = new Intl.DateTimeFormat('ru-RU', {
  timeZone: 'Europe/Moscow',
  day: 'numeric',
  month: 'long',
});
const createdFormatter = new Intl.DateTimeFormat('ru-RU', {
  timeZone: 'Europe/Moscow',
  day: '2-digit',
  month: '2-digit',
  year: 'numeric',
  hour: '2-digit',
  minute: '2-digit',
});
const moneyFormatter = new Intl.NumberFormat('ru-RU', {
  style: 'currency',
  currency: 'RUB',
  minimumFractionDigits: 0,
  maximumFractionDigits: 2,
});
const dateFormatter = new Intl.DateTimeFormat('ru-RU', {
  timeZone: 'Europe/Moscow',
  day: 'numeric',
  month: 'short',
  year: 'numeric',
});
const timeFormatter = new Intl.DateTimeFormat('ru-RU', {
  timeZone: 'Europe/Moscow',
  hour: '2-digit',
  minute: '2-digit',
});

export function money(value: number): string {
  return moneyFormatter.format(value);
}

export function statusLabel(status: OrderStatus): string {
  return {
    new: 'Новый',
    accepted: 'Принят',
    delivering: 'В доставке',
    delivered: 'Доставлен',
    cancelled: 'Отменён',
  }[status];
}

export function deliveryInterval(order: OrderView): string {
  if (!order.delivery.starts_at || !order.delivery.ends_at) return 'В удобное время';
  const start = new Date(order.delivery.starts_at);
  return `${dateFormatter.format(start)} · ${timeFormatter.format(start)}–${timeFormatter.format(new Date(order.delivery.ends_at))}`;
}

export function createdDate(order: OrderView): string {
  return createdFormatter.format(new Date(order.created_at));
}

export function deliveryDate(order: OrderView): string {
  if (order.delivery.type === 'pickup') return 'Самовывоз';
  return order.delivery.starts_at
    ? deliveryDayFormatter.format(new Date(order.delivery.starts_at))
    : 'Дата не указана';
}

export function deliveryWindow(order: OrderView): string {
  if (order.delivery.type === 'pickup') return 'Из магазина';
  const interval =
    order.delivery.starts_at && order.delivery.ends_at
      ? `${timeFormatter.format(new Date(order.delivery.starts_at))}–${timeFormatter.format(new Date(order.delivery.ends_at))}`
      : 'Интервал не указан';
  const district = districtLabel(order.delivery.district);
  return district ? `${interval} · ${district}` : interval;
}

export function districtLabel(district: string | null): string {
  const labels: Record<string, string> = {
    centre: 'Центр',
    north: 'Северный',
    south: 'Южный',
    suburb: 'Пригород',
  };
  return district ? (labels[district] ?? district) : '';
}
