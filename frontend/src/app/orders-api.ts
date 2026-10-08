import { HttpClient, HttpErrorResponse, HttpParams } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { catchError, Observable, throwError } from 'rxjs';
import { ImportBatchResult, OrderPage, OrderStatus } from './orders.models';

@Injectable({ providedIn: 'root' })
export class OrdersApi {
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

    return this.http.get<OrderPage>(this.ordersUrl(shopId), { params });
  }

  importExcel(shopId: string, file: File): Observable<ImportBatchResult> {
    const body = new FormData();
    body.append('file', file, file.name);
    return this.http.post<ImportBatchResult>(`${this.ordersUrl(shopId)}/import/excel`, body);
  }

  exportExcel(shopId: string): Observable<Blob> {
    return this.download(`${this.ordersUrl(shopId)}/export/excel`);
  }

  templateExcel(shopId: string): Observable<Blob> {
    return this.download(`${this.ordersUrl(shopId)}/template/excel`);
  }

  private download(url: string): Observable<Blob> {
    return this.http.get(url, { responseType: 'blob' }).pipe(
      catchError((error: unknown) => {
        if (!(error instanceof HttpErrorResponse) || !(error.error instanceof Blob)) {
          return throwError(() => error);
        }
        return this.decodeDownloadError(error);
      }),
    );
  }

  private decodeDownloadError(error: HttpErrorResponse): Promise<never> {
    return new Promise((_, reject) => {
      const reader = new FileReader();
      reader.onerror = () => reject(error);
      reader.onload = () => {
        try {
          reject(
            new HttpErrorResponse({
              error: JSON.parse(String(reader.result)),
              headers: error.headers,
              status: error.status,
              statusText: error.statusText,
              url: error.url ?? undefined,
            }),
          );
        } catch {
          reject(error);
        }
      };
      reader.readAsText(error.error);
    });
  }

  private ordersUrl(shopId: string): string {
    return `/api/shops/${encodeURIComponent(shopId)}/orders`;
  }
}
