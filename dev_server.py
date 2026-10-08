import os
import json
import secrets
from http.server import HTTPServer, SimpleHTTPRequestHandler
from urllib.parse import urlparse

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
SESSIONS = {}

def read_json_file(filename, fallback):
    path = os.path.join(BASE_DIR, filename)
    if not os.path.exists(path):
        return fallback
    try:
        with open(path, 'r', encoding='utf-8') as f:
            content = f.read().strip()
            if not content:
                return fallback
            return json.loads(content)
    except Exception:
        return fallback

def write_json_file(filename, data):
    path = os.path.join(BASE_DIR, filename)
    with open(path, 'w', encoding='utf-8') as f:
        json.dump(data, f, indent=4, ensure_ascii=False)

class LocalDevHandler(SimpleHTTPRequestHandler):
    def translate_path(self, path):
        path = urlparse(path).path
        if path.startswith('/'):
            path = path[1:]
        return os.path.join(BASE_DIR, path)

    def get_session(self):
        cookie_header = self.headers.get('Cookie', '')
        session_id = None
        for item in cookie_header.split(';'):
            if '=' in item:
                k, v = item.strip().split('=', 1)
                if k == 'alien_session':
                    session_id = v
                    break
        if session_id and session_id in SESSIONS:
            return session_id, SESSIONS[session_id]
        new_id = secrets.token_hex(16)
        SESSIONS[new_id] = {'customer': None, 'admin': None}
        return new_id, SESSIONS[new_id]

    def send_json(self, data, status=200, session_id=None):
        body = json.dumps(data, ensure_ascii=False).encode('utf-8')
        self.send_response(status)
        self.send_header('Content-Type', 'application/json; charset=utf-8')
        self.send_header('Content-Length', str(len(body)))
        self.send_header('Cache-Control', 'no-store')
        if session_id:
            self.send_header('Set-Cookie', f'alien_session={session_id}; Path=/; HttpOnly; SameSite=Strict')
        self.end_headers()
        self.wfile.write(body)

    def send_html_file(self, filename):
        path = os.path.join(BASE_DIR, filename)
        if not os.path.exists(path):
            self.send_error(404, "File not found")
            return
        with open(path, 'rb') as f:
            content = f.read()
        self.send_response(200)
        self.send_header('Content-Type', 'text/html; charset=utf-8')
        self.send_header('Content-Length', str(len(content)))
        self.send_header('Cache-Control', 'no-store')
        self.end_headers()
        self.wfile.write(content)

    def do_GET(self):
        parsed = urlparse(self.path)
        path = parsed.path.rstrip('/')
        if path == '':
            path = '/'

        session_id, session = self.get_session()

        # Clean HTML page routes
        if path in ['/', '/index.html']:
            return self.send_html_file('index.html')
        elif path in ['/dashboard', '/dashboard.html']:
            if not session['admin']:
                return self.send_html_file('admin-login.html')
            return self.send_html_file('dashboard.html')
        elif path in ['/admin-login', '/admin-login.html']:
            if session['admin']:
                self.send_response(302)
                self.send_header('Location', '/dashboard')
                self.end_headers()
                return
            return self.send_html_file('admin-login.html')
        elif path in ['/account', '/account.html']:
            return self.send_html_file('account.html')
        elif path in ['/register', '/register.html']:
            return self.send_html_file('register.html')

        # API Routes
        if path == '/api/auth/status':
            customer = session['customer']
            return self.send_json({
                'authenticated': customer is not None,
                'email': customer['email'] if customer else None,
                'fullName': customer.get('fullName', '') if customer else ''
            }, session_id=session_id)

        if path == '/api/admin/setup/status':
            return self.send_json({
                'setupRequired': False,
                'username': read_json_file('admin-credentials.json', {}).get('username', 'abosalah')
            })

        if path == '/api/admin/credentials':
            if not session['admin']:
                return self.send_json({'error': 'Admin login required.'}, 401)
            admin_data = read_json_file('admin-credentials.json', {'username': 'abosalah'})
            return self.send_json({'username': admin_data.get('username', 'abosalah')})

        if path == '/api/products':
            return self.send_json(read_json_file('products.json', []))

        if path == '/api/theme':
            return self.send_json(read_json_file('theme.json', {
                'bg': '#080909', 'primary': '#c5a45d', 'primary2': '#ead59a',
                'primary3': '#d7d3c8', 'text': '#f3efe5', 'muted': '#aaa69c'
            }))

        if path == '/api/pricing':
            return self.send_json(read_json_file('store-pricing.json', {'divisor': 5.2}))

        if path == '/api/contacts':
            return self.send_json(read_json_file('contacts.json', {
                'discord': 'https://discord.gg/DdukFbQuea',
                'telegram': 'https://t.me/ALIENOFFICIAL_1'
            }))

        if path == '/api/dashboard/orders':
            if not session['admin']:
                return self.send_json({'error': 'Admin login required.'}, 401)
            orders = read_json_file('orders.json', [])
            orders.reverse()
            return self.send_json(orders)

        if path == '/api/account/orders':
            if not session['customer']:
                return self.send_json({'error': 'Sign in required.'}, 401)
            cust_id = session['customer']['id']
            orders = [o for o in read_json_file('orders.json', []) if o.get('customerId') == cust_id]
            return self.send_json(orders)

        return super().do_GET()

    def do_POST(self):
        parsed = urlparse(self.path)
        path = parsed.path.rstrip('/')
        session_id, session = self.get_session()

        content_length = int(self.headers.get('Content-Length', 0))
        body_bytes = self.rfile.read(content_length) if content_length > 0 else b''
        
        data = {}
        if body_bytes:
            try:
                data = json.loads(body_bytes.decode('utf-8'))
            except Exception:
                pass

        if path == '/api/auth/register':
            email = (data.get('email') or '').strip().lower()
            password = data.get('password') or ''
            full_name = (data.get('fullName') or '').strip()

            if not email or '@' not in email:
                return self.send_json({'error': 'Enter a valid email address.'}, 400)
            if len(password) < 10:
                return self.send_json({'error': 'Choose a password with at least 10 characters.'}, 400)
            if not full_name:
                return self.send_json({'error': 'Enter your name (up to 100 characters).'}, 400)

            users = read_json_file('users.json', [])
            for u in users:
                if u.get('email', '').lower() == email:
                    return self.send_json({'error': 'An account with this email already exists. Sign in instead.'}, 409)

            new_user = {
                'id': secrets.token_hex(16),
                'email': email,
                'fullName': full_name,
                'createdAt': '2026-10-03T20:00:00Z'
            }
            users.append(new_user)
            write_json_file('users.json', users)

            session['customer'] = None
            return self.send_json({'authenticated': False, 'email': email}, session_id=session_id)

        if path == '/api/auth/login':
            email = (data.get('email') or '').strip().lower()
            users = read_json_file('users.json', [])
            user = next((u for u in users if u.get('email', '').lower() == email), None)
            if not user:
                return self.send_json({'error': 'Email or password is incorrect.'}, 401)

            session['customer'] = user
            return self.send_json({'authenticated': True, 'email': email}, session_id=session_id)

        if path == '/api/auth/forgot-password':
            email = (data.get('email') or '').strip().lower()
            users = read_json_file('users.json', [])
            user = next((u for u in users if u.get('email', '').lower() == email), None)
            if not user:
                return self.send_json({'error': 'No account found with this email address.'}, 404)
            code = "123456"
            user['resetCode'] = code
            write_json_file('users.json', users)
            return self.send_json({'success': True, 'message': 'Reset code generated.', 'code': code})

        if path == '/api/auth/reset-password':
            email = (data.get('email') or '').strip().lower()
            code = (data.get('code') or '').strip()
            new_password = data.get('newPassword') or ''
            if len(new_password) < 10:
                return self.send_json({'error': 'Choose a new password with at least 10 characters.'}, 400)
            users = read_json_file('users.json', [])
            user = next((u for u in users if u.get('email', '').lower() == email and u.get('resetCode') == code), None)
            if not user:
                return self.send_json({'error': 'Invalid reset code or email.'}, 400)
            user.pop('resetCode', None)
            write_json_file('users.json', users)
            return self.send_json({'success': True, 'message': 'Password reset successfully. You can now sign in.'})

        if path == '/api/auth/logout':
            session['customer'] = None
            return self.send_json({'authenticated': False}, session_id=session_id)

        if path == '/api/admin/login':
            username = data.get('username') or ''
            password = data.get('password') or ''
            admin_data = read_json_file('admin-credentials.json', {'username': 'abosalah'})
            
            if username == admin_data.get('username', 'abosalah'):
                session['admin'] = {'username': username}
                return self.send_json({'authenticated': True}, session_id=session_id)
            return self.send_json({'error': 'Username or password is incorrect.'}, 401)

        if path == '/api/admin/logout':
            session['admin'] = None
            return self.send_json({'authenticated': False}, session_id=session_id)

        if path == '/api/checkout':
            if not session['customer']:
                return self.send_json({'error': 'Sign in to your customer account before checkout.'}, 401)
            product_ids = data.get('productIds', [])
            if not product_ids:
                return self.send_json({'error': 'Add at least one product to your cart before checkout.'}, 400)
            
            products = {p['id']: p for p in read_json_file('products.json', [])}
            items = []
            total = 0.0
            for pid in product_ids:
                if pid in products:
                    p = products[pid]
                    price = float(p.get('storePrice', 5.0))
                    items.append({'id': pid, 'name': p['name'], 'unitPrice': price, 'quantity': 1, 'lineTotal': price})
                    total += price

            order = {
                'id': secrets.token_hex(16),
                'orderNumber': 'AL-20261003-' + secrets.token_hex(3).upper(),
                'customerId': session['customer']['id'],
                'customerName': session['customer'].get('fullName', session['customer']['email']),
                'customerEmail': session['customer']['email'],
                'items': items,
                'total': round(total, 2),
                'createdAt': '2026-10-03T20:00:00Z',
                'status': 'Awaiting payment confirmation',
                'emailStatus': 'not_sent'
            }
            orders = read_json_file('orders.json', [])
            orders.append(order)
            write_json_file('orders.json', orders)

            return self.send_json({'authenticated': True, 'order': order, 'items': items, 'total': round(total, 2)})

        if path == '/api/dashboard/orders/confirm':
            if not session['admin']:
                return self.send_json({'error': 'Admin login required.'}, 401)
            order_id = data.get('id')
            orders = read_json_file('orders.json', [])
            target = None
            for o in orders:
                if o['id'] == order_id:
                    o['status'] = 'Payment confirmed'
                    o['emailStatus'] = 'sent'
                    target = o
                    break
            if target:
                write_json_file('orders.json', orders)
                return self.send_json({'order': target, 'emailSent': True})
            return self.send_json({'error': 'Order not found.'}, 404)

        return self.send_json({'error': 'Not found.'}, 404)

    def do_PUT(self):
        parsed = urlparse(self.path)
        path = parsed.path.rstrip('/')
        session_id, session = self.get_session()

        content_length = int(self.headers.get('Content-Length', 0))
        body_bytes = self.rfile.read(content_length) if content_length > 0 else b''
        data = json.loads(body_bytes.decode('utf-8')) if body_bytes else {}

        if path == '/api/admin/credentials':
            if not session['admin']:
                return self.send_json({'error': 'Admin login required.'}, 401)
            new_username = data.get('newUsername', 'abosalah').strip()
            write_json_file('admin-credentials.json', {
                'username': new_username,
                'passwordHash': '$2y$12$K7XoLf0eGIlkYr6w4.apReNFwXBRpOzgACNCxbTvjVbPal7qpn7XW',
                'credentialVersion': secrets.token_hex(16),
                'updatedAt': '2026-10-03T20:00:00Z'
            })
            session['admin']['username'] = new_username
            return self.send_json({'saved': True, 'username': new_username})

        if path == '/api/dashboard/theme':
            if not session['admin']:
                return self.send_json({'error': 'Admin login required.'}, 401)
            write_json_file('theme.json', data)
            return self.send_json({'saved': True, 'theme': data})

        if path == '/api/dashboard/pricing':
            if not session['admin']:
                return self.send_json({'error': 'Admin login required.'}, 401)
            write_json_file('store-pricing.json', data)
            return self.send_json({'saved': True, **data})

        if path == '/api/dashboard/contacts':
            if not session['admin']:
                return self.send_json({'error': 'Admin login required.'}, 401)
            write_json_file('contacts.json', data)
            return self.send_json({'saved': True, 'contacts': data})

        return self.send_json({'error': 'Not found.'}, 404)

if __name__ == '__main__':
    port = 8000
    server_address = ('', port)
    httpd = HTTPServer(server_address, LocalDevHandler)
    print(f"Server started on http://localhost:{port}")
    try:
        httpd.serve_forever()
    except KeyboardInterrupt:
        pass
    httpd.server_close()
