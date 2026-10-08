import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { ImportBatchResult, OrderPage, OrderView } from './orders.models';
import { OrdersStore } from './orders.store';

describe('OrdersStore', () => {
  let store: OrdersStore;
  let http: HttpTestingController;

  const sampleOrder: OrderView = {
    id: 1,
    marketplace_id: 'MP-1009',
    status: 'new',
    created_at: '2026-10-09T16:00:00+03:00',
    customer: { name: 'Алексей Никитин', phone: '+79003334455' },
    delivery: {
      type: 'delivery',
      district: 'suburb',
      address: 'п. Радостный, 12',
      starts_at: '2026-10-13T10:00:00+03:00',
      ends_at: '2026-10-13T12:00:00+03:00',
    },
    items: [{ sku: 'B-109', name: 'Букет «Классика»', qty: 2, price: 1800 }],
    items_total: 3600,
    marketplace_total: 3000,
    delivery_cost: 700,
    total: 4300,
    needs_review: true,
  };
  const report: ImportBatchResult = {
    summary: { total: 2, created: 1, updated: 0, unchanged: 0, duplicate: 0, rejected: 1 },
    results: [
      {
        index: 0,
        marketplace_id: 'MP-1009',
        outcome: 'created',
        code: 'created',
        message: 'Заказ создан.',
        order_id: 1,
        status: 'new',
        needs_review: true,
        items_total: 3600,
        delivery_cost: 700,
        total: 4300,
        warnings: [
          { code: 'total_mismatch', message: 'Сумма позиций отличается от total маркетплейса.' },
        ],
        errors: [],
      },
      {
        index: 1,
        marketplace_id: 'MP-1007',
        outcome: 'rejected',
        code: 'invalid_phone',
        message: 'Неверный телефон.',
        order_id: null,
        status: null,
        needs_review: null,
        items_total: null,
        delivery_cost: null,
        total: null,
        warnings: [],
        errors: [
          {
            field: 'customer.phone',
            message: 'Не удалось привести телефон к формату +7XXXXXXXXXX.',
          },
        ],
      },
    ],
  };

  function page(items: OrderView[] = [], total = items.length, current = 1): OrderPage {
    return { items, pagination: { page: current, limit: 10, total, pages: Math.ceil(total / 10) } };
  }

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    store = TestBed.inject(OrdersStore);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify({ ignoreCancelled: true }));

  it('loads only when requested and preserves server-calculated ruble amounts and review flags', () => {
    http.expectNone(() => true);
    store.refresh();
    expect(store.listLoading()).toBe(true);
    http.expectOne('/api/shops/1/orders?page=1&limit=10').flush(page([sampleOrder]));

    expect(store.listLoading()).toBe(false);
    expect(store.orders()).toEqual([sampleOrder]);
    expect(store.orders()[0].total).toBe(4300);
    expect(store.hasOrders()).toBe(true);
    expect(store.pagination().total).toBe(1);
  });

  it('cancels a stale list request when the filter changes quickly', () => {
    store.refresh();
    const first = http.expectOne('/api/shops/1/orders?page=1&limit=10');
    store.setStatus('new');
    const second = http.expectOne('/api/shops/1/orders?page=1&limit=10&status=new');
    expect(first.cancelled).toBe(true);
    store.setStatus('accepted');
    expect(second.cancelled).toBe(true);
    http.expectOne('/api/shops/1/orders?page=1&limit=10&status=accepted').flush(page());

    expect(store.status()).toBe('accepted');
    expect(store.listLoading()).toBe(false);
    expect(store.listError()).toBeNull();
  });

  it('validates a shop ID before requesting it and clears the prior shop report and orders', () => {
    expect(store.setShop('../other shop')).toBe(false);
    expect(store.shopId()).toBe('1');
    expect(store.shopError()).toContain('ID магазина');
    http.expectNone(() => true);

    store.importReport.set(report);
    store.orders.set([sampleOrder]);
    expect(store.setShop(' second-shop ')).toBe(true);
    expect(store.shopId()).toBe('second-shop');
    expect(store.orders()).toEqual([]);
    expect(store.importReport()).toBeNull();
    expect(store.shopError()).toBeNull();
    http.expectOne('/api/shops/second-shop/orders?page=1&limit=10').flush(page());
  });

  it('cancels a previous shop request so a late response cannot leak its orders into another shop', () => {
    store.refresh();
    const first = http.expectOne('/api/shops/1/orders?page=1&limit=10');
    store.setShop('2');
    expect(first.cancelled).toBe(true);
    http.expectOne('/api/shops/2/orders?page=1&limit=10').flush(page());
    expect(store.orders()).toEqual([]);
  });

  it('navigates only to available pages and resets to page one when a status changes', () => {
    store.refresh();
    http.expectOne('/api/shops/1/orders?page=1&limit=10').flush(page([sampleOrder], 11));
    store.goToPage(0);
    store.goToPage(3);
    store.goToPage(1.5);
    http.expectNone(() => true);

    store.goToPage(2);
    expect(store.page()).toBe(2);
    http.expectOne('/api/shops/1/orders?page=2&limit=10').flush(page([sampleOrder], 11, 2));
    store.setStatus('delivered');
    expect(store.page()).toBe(1);
    http.expectOne('/api/shops/1/orders?page=1&limit=10&status=delivered').flush(page());
  });

  it('moves to the last available page if the server result shrinks', () => {
    store.refresh();
    http.expectOne('/api/shops/1/orders?page=1&limit=10').flush(page([sampleOrder], 11));
    store.goToPage(2);
    http.expectOne('/api/shops/1/orders?page=2&limit=10').flush(page([], 1, 2));
    expect(store.page()).toBe(1);
    http.expectOne('/api/shops/1/orders?page=1&limit=10').flush(page([sampleOrder]));
    expect(store.orders()).toEqual([sampleOrder]);
  });

  it('shows a recoverable list error, then clears it after a successful retry', () => {
    store.refresh();
    http
      .expectOne('/api/shops/1/orders?page=1&limit=10')
      .flush('Unavailable', { status: 503, statusText: 'Unavailable' });
    expect(store.listError()).toContain('Попробуйте ещё раз');
    expect(store.listLoading()).toBe(false);
    expect(store.orders()).toEqual([]);

    store.refresh();
    expect(store.listError()).toBeNull();
    http.expectOne('/api/shops/1/orders?page=1&limit=10').flush(page([sampleOrder]));
    expect(store.listError()).toBeNull();
    expect(store.hasOrders()).toBe(true);
  });

  it('submits exactly one import on repeated clicks, blocks changing shops, and reloads the selected filter', () => {
    store.setStatus('new');
    http.expectOne('/api/shops/1/orders?page=1&limit=10&status=new').flush(page());

    store.importOrders();
    store.importOrders();
    expect(store.isImporting()).toBe(true);
    expect(store.setShop('2')).toBe(false);
    const asset = http.expectOne('/assets/marketplace-orders.json');
    asset.flush({ orders: [{ id: 'MP-1009' }, { id: 'MP-1007' }] });
    store.importOrders();
    http.expectOne('/api/shops/1/orders/import').flush(report);

    expect(store.isImporting()).toBe(false);
    expect(store.importReport()).toEqual(report);
    expect(store.importReport()?.results[0].warnings[0].code).toBe('total_mismatch');
    expect(store.importReport()?.results[1].errors[0].field).toBe('customer.phone');
    expect(store.notice()).toContain('2');
    expect(store.status()).toBe('new');
    http.expectOne('/api/shops/1/orders?page=1&limit=10&status=new').flush(page([sampleOrder]));
  });

  it('never retries a failed POST automatically and allows an explicit repeat', () => {
    store.importOrders();
    http.expectOne('/assets/marketplace-orders.json').flush({ orders: [{ id: 'MP-1009' }] });
    http.expectOne('/api/shops/1/orders/import').error(new ProgressEvent('error'));
    expect(store.importReport()).toBeNull();
    expect(store.importError()).toContain('Можно повторить');
    expect(store.isImporting()).toBe(false);
    http.expectNone(() => true);

    store.importOrders();
    expect(store.importError()).toBeNull();
    http.expectOne('/assets/marketplace-orders.json').flush({ orders: [{ id: 'MP-1009' }] });
    http.expectOne('/api/shops/1/orders/import').flush(report);
    http.expectOne('/api/shops/1/orders?page=1&limit=10').flush(page([sampleOrder]));
    expect(store.importReport()).toEqual(report);
  });

  it('keeps a successful import report if refreshing the saved list fails', () => {
    store.importOrders();
    http.expectOne('/assets/marketplace-orders.json').flush({ orders: [{ id: 'MP-1009' }] });
    http.expectOne('/api/shops/1/orders/import').flush(report);
    http
      .expectOne('/api/shops/1/orders?page=1&limit=10')
      .flush('Unavailable', { status: 503, statusText: 'Unavailable' });
    expect(store.importReport()).toEqual(report);
    expect(store.importError()).toBeNull();
    expect(store.listError()).not.toBeNull();
  });

  it('surfaces API validation details without converting them to HTML', () => {
    store.refresh();
    http
      .expectOne('/api/shops/1/orders?page=1&limit=10')
      .flush(
        { detail: 'Неизвестный статус заказа.' },
        { status: 422, statusText: 'Unprocessable Entity' },
      );
    expect(store.listError()).toBe('Неизвестный статус заказа.');
  });
});
