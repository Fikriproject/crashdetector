import { TestBed } from '@angular/core/testing';

import { MqttHandler } from './mqtt-handler';

describe('MqttHandler', () => {
  let service: MqttHandler;

  beforeEach(() => {
    TestBed.configureTestingModule({});
    service = TestBed.inject(MqttHandler);
  });

  it('should be created', () => {
    expect(service).toBeTruthy();
  });
});
