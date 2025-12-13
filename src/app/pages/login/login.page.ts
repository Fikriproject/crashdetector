import { Component, OnInit } from '@angular/core';
import { Router } from '@angular/router';
import { ToastController } from '@ionic/angular';
import { Auth } from '../../services/auth';

@Component({
  selector: 'app-login',
  templateUrl: './login.page.html',
  styleUrls: ['./login.page.scss'],
  standalone: false,
})
export class LoginPage implements OnInit {
  loginType: 'user' | 'emergency' = 'user';
  phone = '';
  password = '';
  emergencyPhone = '';

  constructor(
    private auth: Auth,
    private router: Router,
    private toastCtrl: ToastController
  ) { }

  ngOnInit() {
  }

  setLoginType(type: 'user' | 'emergency') {
    this.loginType = type;
  }

  async onLogin() {
    if (!this.phone || !this.password) {
      this.showToast('Mohon isi semua data');
      return;
    }

    this.auth.login(this.phone, this.password).subscribe(async success => {
      if (success) {
        this.router.navigate(['/dashboard']);
      } else {
        this.showToast('Login gagal. Periksa nomor dan password.');
      }
    });
  }

  async onEmergencyLogin() {
    if (!this.emergencyPhone) {
      this.showToast('Mohon isi nomor darurat');
      return;
    }

    this.auth.loginAsEmergency(this.emergencyPhone).subscribe(async success => {
      if (success) {
        this.showToast('Login Darurat Berhasil');
        this.router.navigate(['/dashboard']);
      } else {
        this.showToast('Nomor tidak ditemukan sebagai kontak darurat.');
      }
    });
  }

  goToRegister() {
    this.router.navigate(['/register']);
  }

  async showToast(msg: string) {
    const toast = await this.toastCtrl.create({
      message: msg,
      duration: 2000,
      position: 'bottom',
      color: this.loginType === 'emergency' && msg.includes('Berhasil') ? 'success' : 'danger'
    });
    toast.present();
  }
}
