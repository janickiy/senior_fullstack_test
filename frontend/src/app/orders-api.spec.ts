import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { OrdersApi } from './orders-api';
import { HttpErrorResponse } from '@angular/common/http';
import { firstValueFrom } from 'rxjs';
import { ImportBatchResult, OrderPage } from './orders.models';
import { importReport } from './test-fixtures';

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

  it('uploads the original Excel file as multipart data and returns the import report', async () => {
    const file = new File(['xlsx'], 'orders.xlsx');
    let received: ImportBatchResult | undefined;
    api.importExcel('flower_shop', file).subscribe((report) => {
      received = report;
    });
    const request = http.expectOne('/api/shops/flower_shop/orders/import/excel');
    expect(request.request.method).toBe('POST');
    expect(request.request.body).toBeInstanceOf(FormData);
    const uploaded = request.request.body.get('file') as File;
    expect(uploaded).toBeInstanceOf(File);
    expect(uploaded.name).toBe(file.name);
    expect(uploaded.size).toBe(file.size);
    expect(request.request.headers.has('Content-Type')).toBe(false);
    request.flush(importReport);
    expect(received).toEqual(importReport);
    const content = await new Promise((resolve, reject) => {
      const reader = new FileReader();
      reader.onload = () => resolve(reader.result);
      reader.onerror = reject;
      reader.readAsText(uploaded);
    });
    expect(content).toBe('xlsx');
  });

  it('downloads all shop orders as an Excel blob without pagination or status parameters', () => {
    const file = new Blob(['xlsx']);
    let received: Blob | undefined;
    api.exportExcel('flower_shop').subscribe((blob) => {
      received = blob;
    });
    const request = http.expectOne('/api/shops/flower_shop/orders/export/excel');
    expect(request.request.method).toBe('GET');
    expect(request.request.responseType).toBe('blob');
    expect(request.request.params.keys()).toEqual([]);
    request.flush(file);
    expect(received).toBe(file);
  });

  it('downloads the Excel template as a blob', () => {
    api.templateExcel('1').subscribe();
    const request = http.expectOne('/api/shops/1/orders/template/excel');
    expect(request.request.responseType).toBe('blob');
    request.flush(new Blob(['xlsx']));
  });

  it('decodes API JSON errors received as blobs instead of reporting an opaque file', async () => {
    const response = firstValueFrom(api.exportExcel('1')).catch(
      (error: HttpErrorResponse) => error,
    );
    http.expectOne('/api/shops/1/orders/export/excel').flush(
      new Blob([JSON.stringify({ detail: 'Невозможно сформировать выгрузку.' })], {
        type: 'application/json',
      }),
      { status: 422, statusText: 'Unprocessable Entity' },
    );
    const error = await response;
    expect(error).toBeInstanceOf(HttpErrorResponse);
    expect((error as HttpErrorResponse).error.detail).toBe('Невозможно сформировать выгрузку.');
  });
});
