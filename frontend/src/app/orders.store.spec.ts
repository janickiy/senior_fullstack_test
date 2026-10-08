import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { ImportBatchResult, OrderPage, OrderView } from './orders.models';
import { OrdersStore } from './orders.store';
import {
  invalidPhoneResult,
  reviewImportResult,
  reviewOrder as sampleOrder,
} from './test-fixtures';

describe('OrdersStore', () => {
  let store: OrdersStore;
  let http: HttpTestingController;

  const report: ImportBatchResult = {
    summary: { total: 2, created: 1, updated: 0, unchanged: 0, duplicate: 0, rejected: 1 },
    results: [
      {
        ...reviewImportResult,
        index: 0,
      },
      {
        ...invalidPhoneResult,
        index: 1,
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

  afterEach(() => {
    http.verify({ ignoreCancelled: true });
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
    vi.useRealTimers();
  });

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

    const file = new File(['xlsx'], 'orders.xlsx');
    store.importExcel(file);
    store.importExcel(file);
    expect(store.isImporting()).toBe(true);
    expect(store.setShop('2')).toBe(false);
    store.importExcel(file);
    http.expectOne('/api/shops/1/orders/import/excel').flush(report);

    expect(store.isImporting()).toBe(false);
    expect(store.importReport()).toEqual(report);
    expect(store.importReport()?.results[0].warnings[0].code).toBe('total_mismatch');
    expect(store.importReport()?.results[1].errors[0].field).toBe('customer.phone');
    expect(store.notice()).toContain('2');
    expect(store.status()).toBe('new');
    http.expectOne('/api/shops/1/orders?page=1&limit=10&status=new').flush(page([sampleOrder]));
  });

  it('never retries a failed POST automatically and allows an explicit repeat', () => {
    const file = new File(['xlsx'], 'orders.xlsx');
    store.importExcel(file);
    http.expectOne('/api/shops/1/orders/import/excel').error(new ProgressEvent('error'));
    expect(store.importReport()).toBeNull();
    expect(store.importError()).toContain('Можно повторить');
    expect(store.isImporting()).toBe(false);
    http.expectNone(() => true);

    store.importExcel(file);
    expect(store.importError()).toBeNull();
    http.expectOne('/api/shops/1/orders/import/excel').flush(report);
    http.expectOne('/api/shops/1/orders?page=1&limit=10').flush(page([sampleOrder]));
    expect(store.importReport()).toEqual(report);
  });

  it('keeps a successful import report if refreshing the saved list fails', () => {
    store.importExcel(new File(['xlsx'], 'orders.xlsx'));
    http.expectOne('/api/shops/1/orders/import/excel').flush(report);
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

  it('validates Excel extension and size before uploading, and accepts uppercase XLSX', () => {
    store.importExcel(new File(['content'], 'orders.csv'));
    expect(store.importError()).toContain('.xlsx');
    http.expectNone(() => true);

    const oversized = new File(['content'], 'orders.xlsx');
    Object.defineProperty(oversized, 'size', { value: 10 * 1024 * 1024 + 1 });
    store.importExcel(oversized);
    expect(store.importError()).toContain('10 МБ');
    expect(store.isImporting()).toBe(false);
    http.expectNone(() => true);

    store.importExcel(new File(['content'], 'orders.XLSX'));
    expect(store.importError()).toBeNull();
    http.expectOne('/api/shops/1/orders/import/excel').flush(report);
    http.expectOne('/api/shops/1/orders?page=1&limit=10').flush(page());
  });

  it('ignores another selected file during import and preserves the report filename, source rows and filter', () => {
    store.setStatus('accepted');
    http.expectOne('/api/shops/1/orders?page=1&limit=10&status=accepted').flush(page());
    const file = new File(['content'], 'orders.xlsx');
    store.importExcel(file);
    store.importExcel(file);
    store.importExcel(new File(['other'], 'other-orders.xlsx'));
    expect(store.isImporting()).toBe(true);
    expect(store.importFileName()).toBe('orders.xlsx');
    const excelReport = {
      ...report,
      results: report.results.map((result) => ({ ...result, source_row: result.index + 2 })),
    };
    http.expectOne('/api/shops/1/orders/import/excel').flush(excelReport);
    expect(store.importReport()).toEqual(excelReport);
    expect(store.isImporting()).toBe(false);
    http.expectOne('/api/shops/1/orders?page=1&limit=10&status=accepted').flush(page());

    expect(store.importFileName()).toBe('orders.xlsx');
  });

  it('shows Excel validation errors and allows explicitly uploading the same file again', () => {
    const file = new File(['content'], 'orders.xlsx');
    store.importExcel(file);
    http
      .expectOne('/api/shops/1/orders/import/excel')
      .flush(
        { detail: 'Не найдена колонка id.' },
        { status: 422, statusText: 'Unprocessable Entity' },
      );
    expect(store.importError()).toBe('Не найдена колонка id.');
    expect(store.isImporting()).toBe(false);
    expect(store.importReport()).toBeNull();
    http.expectNone(() => true);
    store.importExcel(file);
    http.expectOne('/api/shops/1/orders/import/excel').flush(report);
    http.expectOne('/api/shops/1/orders?page=1&limit=10').flush(page());
  });

  it('downloads all orders with a shop filename, guards repeated clicks and revokes the URL after initiation', () => {
    vi.useFakeTimers();
    const create = vi.fn().mockReturnValue('blob:orders');
    const revoke = vi.fn();
    vi.stubGlobal('URL', { createObjectURL: create, revokeObjectURL: revoke });
    let filename = '';
    vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (
      this: HTMLAnchorElement,
    ) {
      filename = this.download;
      expect(this.href).toBe('blob:orders');
      expect(this.isConnected).toBe(true);
      expect(revoke).not.toHaveBeenCalled();
    });

    store.setStatus('cancelled');
    http.expectOne('/api/shops/1/orders?page=1&limit=10&status=cancelled').flush(page());
    store.exportExcel();
    store.exportExcel();
    expect(store.isExporting()).toBe(true);
    const blob = new Blob(['xlsx']);
    http.expectOne('/api/shops/1/orders/export/excel').flush(blob);
    expect(create).toHaveBeenCalledWith(blob);
    expect(filename).toBe('orders-shop-1.xlsx');
    expect(store.isExporting()).toBe(false);
    expect(revoke).not.toHaveBeenCalled();
    expect(document.querySelector('a[download="orders-shop-1.xlsx"]')).toBeNull();
    vi.advanceTimersByTime(1000);
    expect(revoke).toHaveBeenCalledWith('blob:orders');
  });

  it('downloads a named Excel template and shows a recoverable error without triggering a download', () => {
    vi.useFakeTimers();
    const create = vi.fn().mockReturnValue('blob:template');
    vi.stubGlobal('URL', { createObjectURL: create, revokeObjectURL: vi.fn() });
    let filename = '';
    vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (
      this: HTMLAnchorElement,
    ) {
      filename = this.download;
    });
    store.downloadTemplate();
    http.expectOne('/api/shops/1/orders/template/excel').error(new ProgressEvent('error'));
    expect(store.downloadError()).toContain('Не удалось скачать шаблон Excel');
    expect(store.isTemplateLoading()).toBe(false);
    expect(create).not.toHaveBeenCalled();

    store.downloadTemplate();
    expect(store.downloadError()).toBeNull();
    http.expectOne('/api/shops/1/orders/template/excel').flush(new Blob(['xlsx']));
    expect(filename).toBe('orders-template.xlsx');
    expect(store.isTemplateLoading()).toBe(false);
    vi.runAllTimers();
  });
});
