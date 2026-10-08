import type { ImportBatchResult, ImportOrderResult, OrderPage, OrderView } from './orders.models';

// Ответы API для проверки поведения интерфейса через HTTP, без подмены store.
export const savedOrder: OrderView = {
  id: 1,
  marketplace_id: 'MP-1001',
  status: 'new',
  created_at: '2026-10-08T09:00:00+03:00',
  customer: { name: 'Анна Лебедева', phone: '+79001234567' },
  delivery: {
    type: 'delivery',
    district: 'centre',
    address: 'ул. Большая Садовая, 10, кв. 4',
    starts_at: '2026-10-10T14:00:00+03:00',
    ends_at: '2026-10-10T16:00:00+03:00',
  },
  items: [{ sku: 'B-101', name: 'Букет «Нежность»', qty: 1, price: 3200 }],
  items_total: 3200,
  marketplace_total: 3200,
  delivery_cost: 300,
  total: 3500,
  needs_review: false,
};

export const reviewOrder: OrderView = {
  ...savedOrder,
  id: 9,
  marketplace_id: 'MP-1009',
  customer: { name: 'Алексей Никитин', phone: '+79003334455' },
  delivery: {
    ...savedOrder.delivery,
    district: 'suburb',
    address: 'п. Радостный, 12',
  },
  items: [{ sku: 'B-109', name: 'Букет «Классика»', qty: 2, price: 1800 }],
  items_total: 3600,
  marketplace_total: 3000,
  delivery_cost: 700,
  total: 4300,
  needs_review: true,
};

export const emptyPage: OrderPage = {
  items: [],
  pagination: { page: 1, limit: 10, total: 0, pages: 0 },
};

export const ordersPage: OrderPage = {
  items: [savedOrder, reviewOrder],
  pagination: { page: 1, limit: 10, total: 2, pages: 1 },
};

const acceptedResult: ImportOrderResult = {
  index: 0,
  marketplace_id: 'MP-1001',
  outcome: 'created',
  code: 'created',
  message: 'Заказ создан.',
  order_id: 1,
  status: 'new',
  needs_review: false,
  items_total: 3200,
  delivery_cost: 300,
  total: 3500,
  warnings: [],
  errors: [],
};

const rejectedResult: ImportOrderResult = {
  ...acceptedResult,
  outcome: 'rejected',
  order_id: null,
  status: null,
  needs_review: null,
  items_total: null,
  delivery_cost: null,
  total: null,
};

export const importReport: ImportBatchResult = {
  summary: { total: 11, created: 7, updated: 0, unchanged: 0, duplicate: 1, rejected: 3 },
  results: [
    acceptedResult,
    { ...acceptedResult, index: 1, marketplace_id: 'MP-1002', status: 'accepted' },
    { ...acceptedResult, index: 2, marketplace_id: 'MP-1003' },
    { ...acceptedResult, index: 3, marketplace_id: 'MP-1004' },
    { ...acceptedResult, index: 4, marketplace_id: 'MP-1005', status: 'delivered' },
    {
      ...rejectedResult,
      index: 5,
      marketplace_id: 'MP-1006',
      code: 'unknown_district',
      message: 'Для района zarechye не задан тариф доставки.',
    },
    {
      ...rejectedResult,
      index: 6,
      marketplace_id: 'MP-1007',
      code: 'invalid_phone',
      message: 'Некорректный телефон покупателя.',
    },
    {
      ...rejectedResult,
      index: 7,
      marketplace_id: 'MP-1008',
      code: 'unknown_status',
      message: 'Неизвестный статус PACKING.',
    },
    {
      ...acceptedResult,
      index: 8,
      marketplace_id: 'MP-1009',
      needs_review: true,
      items_total: 3600,
      delivery_cost: 700,
      total: 4300,
      warnings: [
        {
          code: 'total_mismatch',
          message: 'Сумма позиций отличается от total маркетплейса: нужна проверка.',
        },
      ],
    },
    {
      ...rejectedResult,
      index: 9,
      marketplace_id: 'MP-1001',
      outcome: 'duplicate',
      code: 'duplicate_in_batch',
      message: 'Повтор идентификатора в пачке: обработано первое вхождение.',
    },
    { ...acceptedResult, index: 10, marketplace_id: 'MP-1010', status: 'cancelled' },
  ],
};

export const repeatedImportReport: ImportBatchResult = {
  summary: { total: 4, created: 0, updated: 1, unchanged: 2, duplicate: 1, rejected: 0 },
  results: [
    {
      ...acceptedResult,
      outcome: 'updated',
      code: 'status_updated',
      status: 'accepted',
      message: 'Обновлён только статус заказа.',
    },
    {
      ...acceptedResult,
      index: 1,
      marketplace_id: 'MP-1002',
      outcome: 'unchanged',
      code: 'no_changes',
      message: 'Статус не изменился; остальные данные сохранены без изменений.',
    },
    {
      ...acceptedResult,
      index: 2,
      marketplace_id: 'MP-1005',
      outcome: 'unchanged',
      code: 'final_status_locked',
      status: 'delivered',
      message: 'Финальный статус сохранён; изменение отклонено.',
    },
    {
      ...rejectedResult,
      index: 3,
      outcome: 'duplicate',
      code: 'duplicate_in_batch',
      message: 'Повтор идентификатора в пачке: обработано первое вхождение.',
    },
  ],
};
