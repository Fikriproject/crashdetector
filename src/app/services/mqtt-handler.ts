import { Injectable } from '@angular/core';
import { MqttService, IMqttMessage } from 'ngx-mqtt';
import { Observable } from 'rxjs';
import { map } from 'rxjs/operators';

@Injectable({
  providedIn: 'root'
})
export class MqttHandler {
  constructor(private _mqttService: MqttService) { }

  /**
   * Subscribe to a topic and return parsed JSON or string
   */
  observe(topic: string): Observable<any> {
    return this._mqttService.observe(topic).pipe(
      map((message: IMqttMessage) => {
        const payload = message.payload.toString();
        try {
          return JSON.parse(payload);
        } catch (e) {
          return payload;
        }
      })
    );
  }

  publish(topic: string, message: string) {
    this._mqttService.unsafePublish(topic, message, { qos: 1, retain: true });
  }
}
