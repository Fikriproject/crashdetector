import { Component, OnInit, OnDestroy } from '@angular/core';
import { Router } from '@angular/router';
import { Auth, User } from '../../services/auth';
import { MqttHandler } from '../../services/mqtt-handler';
import { Subscription } from 'rxjs';

@Component({
  selector: 'app-dashboard',
  templateUrl: './dashboard.page.html',
  styleUrls: ['./dashboard.page.scss'],
  standalone: false,
})
export class DashboardPage implements OnInit, OnDestroy {
  currentUser: User | null = null;
  sensorData: any = { pitch: 0, roll: 0, gyro: 'X:0 Y:0 Z:0' };
  isAccident = false;
  subscription: Subscription | null = null;

  constructor(private auth: Auth, private mqtt: MqttHandler, private router: Router) { }

  ngOnInit() {
    this.currentUser = this.auth.getCurrentUser();
    if (this.currentUser) {
      this.subscribeToDevice(this.currentUser.deviceId);
    }
  }

  subscribeToDevice(deviceId: string) {
    // Topic: device/{ID}/data
    // Mock Topic for demo: 'accident/demo' or use the device ID
    // For this prototype, I'll use a generic topic if the deviceId is the default one, or the deviceId
    const topic = `device/${deviceId}/data`;
    console.log('Subscribing to:', topic);

    this.subscription = this.mqtt.observe(topic).subscribe((data: any) => {
      console.log('Got data:', data);

      // Expected Format: { pitch: number, roll: number, gyro: string, accident: boolean }
      // If data is just a string, try to parse or handle
      if (typeof data === 'object') {
        this.sensorData = { ...this.sensorData, ...data };

        if (data.accident !== undefined) {
          this.isAccident = data.accident;
        } else {
          // Fallback logic
          this.checkAccident(this.sensorData.pitch, this.sensorData.roll);
        }
      }
    });
  }

  checkAccident(pitch: number, roll: number) {
    // Threshold example: > 60 degrees tilt
    if (Math.abs(pitch) > 60 || Math.abs(roll) > 60) {
      this.isAccident = true;
    } else {
      this.isAccident = false;
    }
  }

  getAbsPercent(val: number): number {
    // Clamp 0-90 degrees to 0-100%
    const p = (Math.abs(val) / 90) * 100;
    return p > 100 ? 100 : p;
  }

  goToProfile() {
    this.router.navigate(['/profile']);
  }

  logout() {
    if (this.subscription) this.subscription.unsubscribe();
    this.auth.logout();
  }

  ngOnDestroy() {
    if (this.subscription) this.subscription.unsubscribe();
  }
}
