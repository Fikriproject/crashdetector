import { Injectable } from '@angular/core';
import { Router } from '@angular/router';
import { BehaviorSubject, Observable, of } from 'rxjs';
import { HttpClient } from '@angular/common/http';
import { map, tap, catchError } from 'rxjs/operators';

export interface User {
  id?: string;
  name: string;
  simNumber: string;
  deviceId: string;
  emergencyNumber: string;
  password?: string;
  phone: string;
}

@Injectable({
  providedIn: 'root'
})
export class Auth {
  private _currentUser = new BehaviorSubject<User | null>(null);
  public currentUser$ = this._currentUser.asObservable();

  // Storage Key
  private readonly USER_KEY = 'AccidentUser';

  // API URL - Change this to your local server IP if testing on real device
  // For Emulator/Browser on the same PC: localhost is fine.
  private apiUrl = 'http://localhost/accident-api';

  constructor(
    private router: Router,
    private http: HttpClient
  ) {
    // Load local user if exists
    const stored = localStorage.getItem(this.USER_KEY);
    if (stored) {
      this._currentUser.next(JSON.parse(stored));
    }
  }

  register(user: User): Observable<boolean> {
    return this.http.post<any>(`${this.apiUrl}/register.php`, user).pipe(
      map(res => {
        return res.success;
      }),
      catchError(e => {
        console.error('Register API error', e);
        return of(false);
      })
    );
  }

  login(phone: string, password: string): Observable<boolean> {
    return this.http.post<any>(`${this.apiUrl}/login.php`, { phone, password }).pipe(
      map(res => {
        if (res.success && res.user) {
          this.setCurrentUser(res.user);
          return true;
        }
        return false;
      }),
      catchError(e => {
        console.error('Login API error', e);
        return of(false);
      })
    );
  }

  loginAsEmergency(phone: string): Observable<boolean> {
    return this.http.post<any>(`${this.apiUrl}/emergency_login.php`, { emergencyNumber: phone }).pipe(
      map(res => {
        if (res.success && res.user) {
          console.log('Emergency Login found user:', res.user.name);
          this.setCurrentUser(res.user);
          return true;
        }
        return false;
      }),
      catchError(e => {
        console.error('Emergency Login error', e);
        return of(false);
      })
    );
  }

  updateProfile(updatedUser: User): Observable<boolean> {
    return this.http.post<any>(`${this.apiUrl}/update_profile.php`, updatedUser).pipe(
      map(res => {
        if (res.success) {
          this.setCurrentUser(updatedUser);
          return true;
        }
        return false;
      }),
      catchError(e => of(false))
    );
  }

  logout() {
    this._currentUser.next(null);
    localStorage.removeItem(this.USER_KEY);
    this.router.navigate(['/pages/login']);
  }

  private setCurrentUser(user: User) {
    this._currentUser.next(user);
    localStorage.setItem(this.USER_KEY, JSON.stringify(user));
  }

  getCurrentUser() {
    return this._currentUser.value;
  }
}
