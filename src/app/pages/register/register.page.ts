import { Component, OnInit } from '@angular/core';
import { Router } from '@angular/router';
import { ToastController } from '@ionic/angular';
import { Auth, User } from '../../services/auth';

@Component({
  selector: 'app-register',
  templateUrl: './register.page.html',
  styleUrls: ['./register.page.scss'],
  standalone: false,
})
export class RegisterPage implements OnInit {
  user: User = {
    id: '',
    name: '',
    phone: '',
    password: '',
    deviceId: '',
    simNumber: '',
    emergencyNumber: ''
  };

  constructor(
    private auth: Auth,
    private router: Router,
    private toastCtrl: ToastController
  ) { }

  ngOnInit() {
  }

  async onRegister() {
    if (!this.user.name || !this.user.phone || !this.user.password || !this.user.deviceId || !this.user.simNumber || !this.user.emergencyNumber) {
      const toast = await this.toastCtrl.create({ message: 'Mohon lengkapi semua data', duration: 2000 });
      toast.present();
      return;
    }

    // Generate simple ID
    this.user.id = Math.random().toString(36).substr(2, 9);

    this.auth.register(this.user).subscribe(async success => {
      const toast = await this.toastCtrl.create({
        message: 'Registrasi Berhasil! Silakan Login.',
        duration: 2000,
        color: 'success'
      });
      toast.present();
      this.router.navigate(['/login']);
    });
  }
}
