import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { OrdersApi } from './orders/infrastructure/orders-api';
import { ImportBatchResult, MarketplaceBatch, OrderPage } from './orders/domain/orders.models';

describe('OrdersApi', () => {
  let api: OrdersApi;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    api = TestBed.inject(OrdersApi);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('requests the selected shop, server-side status filter and pagination', () => {
    const response: OrderPage = {
      items: [],
      pagination: { page: 2, limit: 10, total: 11, pages: 2 },
    };
    let received: OrderPage | undefined;
    api.list('flower_shop', 'accepted', 2, 10).subscribe((value) => {
      received = value;
    });

    const request = http.expectOne('/api/shops/flower_shop/orders?page=2&limit=10&status=accepted');
    expect(request.request.method).toBe('GET');
    request.flush(response);
    expect(received).toEqual(response);
  });

  it('omits an empty filter and safely encodes the shop path segment', () => {
    api.list('shop/other', '', 1, 10).subscribe();
    const request = http.expectOne('/api/shops/shop%2Fother/orders?page=1&limit=10');
    expect(request.request.params.has('status')).toBe(false);
    request.flush({ items: [], pagination: { page: 1, limit: 10, total: 0, pages: 0 } });
  });

  it('passes the mock marketplace batch unchanged to Symfony and returns its report', () => {
    const batch: MarketplaceBatch = {
      orders: [{ id: 'MP-1001', customer: { phone: '123' } }, null],
    };
    const report: ImportBatchResult = {
      summary: { total: 2, created: 0, updated: 0, unchanged: 0, duplicate: 0, rejected: 2 },
      results: [],
    };
    let received: ImportBatchResult | undefined;
    api.importOrders('1').subscribe((value) => {
      received = value;
    });

    const asset = http.expectOne('/assets/marketplace-orders.json');
    expect(asset.request.method).toBe('GET');
    http.expectNone('/api/shops/1/orders/import');
    asset.flush(batch);

    const request = http.expectOne('/api/shops/1/orders/import');
    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual(batch);
    request.flush(report);
    expect(received).toEqual(report);
  });

  it('does not submit an import when the marketplace response could not be loaded', () => {
    let failed = false;
    api.importOrders('1').subscribe({
      error: () => {
        failed = true;
      },
    });
    http
      .expectOne('/assets/marketplace-orders.json')
      .flush('Unavailable', { status: 503, statusText: 'Unavailable' });
    expect(failed).toBe(true);
    http.expectNone('/api/shops/1/orders/import');
  });
});
