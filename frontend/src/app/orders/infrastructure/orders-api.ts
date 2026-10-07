import { HttpClient, HttpErrorResponse, HttpParams } from '@angular/common/http';
import { inject, Injectable, Provider } from '@angular/core';
import { catchError, Observable, switchMap, throwError } from 'rxjs';
import { OrdersGateway, OrdersRequestError } from '../application/orders-gateway';
import {
  ImportBatchResult,
  MarketplaceBatch,
  OrderPage,
  OrderStatus,
} from '../domain/orders.models';

@Injectable({ providedIn: 'root' })
export class OrdersApi implements OrdersGateway {
  private readonly http = inject(HttpClient);

  list(
    shopId: string,
    status: OrderStatus | '',
    page: number,
    limit: number,
  ): Observable<OrderPage> {
    let params = new HttpParams().set('page', page).set('limit', limit);
    if (status) {
      params = params.set('status', status);
    }

    return this.http
      .get<OrderPage>(this.ordersUrl(shopId), { params })
      .pipe(catchError(mapHttpError));
  }

  importOrders(shopId: string): Observable<ImportBatchResult> {
    return this.http.get<MarketplaceBatch>('/assets/marketplace-orders.json').pipe(
      switchMap((batch) =>
        this.http.post<ImportBatchResult>(`${this.ordersUrl(shopId)}/import`, batch),
      ),
      catchError(mapHttpError),
    );
  }

  private ordersUrl(shopId: string): string {
    return `/api/shops/${encodeURIComponent(shopId)}/orders`;
  }
}

export function provideOrdersApi(): Provider {
  return { provide: OrdersGateway, useExisting: OrdersApi };
}

function mapHttpError(error: unknown): Observable<never> {
  if (error instanceof HttpErrorResponse && error.status >= 400 && error.status < 500) {
    const body: unknown = error.error;
    if (
      typeof body === 'object' &&
      body !== null &&
      'detail' in body &&
      typeof body.detail === 'string' &&
      body.detail.trim()
    ) {
      return throwError(() => new OrdersRequestError(body.detail as string));
    }
  }
  return throwError(() => new OrdersRequestError());
}
