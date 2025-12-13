import { Component, OnInit } from '@angular/core';
import { Router } from '@angular/router';
import { ToastController } from '@ionic/angular';
import { Auth, User } from '../../services/auth';

@Component({
  selector: 'app-profile',
  templateUrl: './profile.page.html',
  styleUrls: ['./profile.page.scss'],
  standalone: false,
})
export class ProfilePage implements OnInit {
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
    private toastCtrl: ToastController,
    private router: Router
  ) { }

  ngOnInit() {
    const currentUser = this.auth.getCurrentUser();
    if (currentUser) {
      // Clone user object to avoid direct mutation before saving
      this.user = { ...currentUser };
    } else {
      this.router.navigate(['/login']);
    }
  }

  async saveProfile() {
    if (!this.user.name || !this.user.emergencyNumber) {
      this.showToast('Nama dan Nomor Darurat wajib diisi', 'danger');
      return;
    }

    this.auth.updateProfile(this.user).subscribe(success => {
      if (success) {
        this.showToast('Profil berhasil diperbarui!', 'success');
        // Optional: Go back to dashboard on success
        // this.router.navigate(['/dashboard']);
      } else {
        this.showToast('Gagal memperbarui profil.', 'danger');
      }
    });
  }

  async showToast(msg: string, color: string) {
    const toast = await this.toastCtrl.create({
      message: msg,
      duration: 2000,
      color: color,
      position: 'bottom',
      cssClass: 'cozy-toast' // We can define this later if needed
    });
    toast.present();
  }
}
