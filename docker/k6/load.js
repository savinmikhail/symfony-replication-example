import http from 'k6/http';
import { check, sleep } from 'k6';

export const options = {
  vus: 100,
  duration: '300s',
};

const BASE_URL = __ENV.BASE_URL || 'http://nginx:8080';

export default function () {
  // create product
  const payload = JSON.stringify({
    name: `Product-${Math.random().toString(36).substring(2, 8)}`,
    price: (Math.random() * 100).toFixed(2),
  });

  const headers = { 'Content-Type': 'application/json' };
  const createRes = http.post(`${BASE_URL}/products`, payload, { headers });
  check(createRes, { 'create status is 201': (r) => r.status === 201 });

  // list products
  const listRes = http.get(`${BASE_URL}/products`);
  check(listRes, { 'list status is 200': (r) => r.status === 200 });

  sleep(1);
}

