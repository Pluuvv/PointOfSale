<?php
defined('BASEPATH') OR exit('No direct script access allowed');

// --- KELAS: Dashboard Controller ---
// Mengelola halaman utama dan navigasi berdasarkan role pengguna.
// Hanya bisa diakses oleh pengguna yang sudah login.

class Dashboard extends CI_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->library('session');
        $this->load->helper('url');
        $this->load->model('User_model');
        $this->load->model('Product_model');
        $this->load->model('Order_model');
        $this->load->model('Settings_model');

        if (!$this->session->userdata('logged_in')) redirect('auth');
    }

    // --- FUNGSI: Halaman Utama Dashboard ---
    // Mengarahkan pengguna ke halaman sesuai role mereka (admin, cashier, atau guest).
    public function index() {
        $role = $this->session->userdata('role');
        if ($role == 'admin') $this->stok();
        elseif ($role == 'cashier') $this->pesanan();
        else $this->self_service();
    }

    // --- FUNGSI: Halaman Manajemen Meja ---
    // Menampilkan denah meja dan kontrol untuk admin/cashier.
    public function meja() {
        $this->_check_access(['admin', 'cashier']);
        $data['busy_tables'] = $this->Order_model->get_busy_tables();
        $data['total_tables'] = $this->Settings_model->get_total_tables();
        $this->_render('meja_view', 'Manajemen Meja', $data);
    }

    // --- FUNGSI: Tambah Meja Baru ---
    // Menambah jumlah meja total di pengaturan (hanya admin).
    public function add_table_action() {
        $this->_check_access(['admin']);
        $current = $this->Settings_model->get_total_tables();
        $new_total = $current + 1;
        if($this->Settings_model->update_setting('total_tables', $new_total)) {
            echo json_encode(['status' => 'success', 'new_total' => $new_total]);
        } else {
            echo json_encode(['status' => 'error']);
        }
    }
    
    // [BARU] API KURANGI MEJA
    public function reduce_table_action() {
        $this->_check_access(['admin']);
        
        $current = $this->Settings_model->get_total_tables();
        
        // Validasi 1: Gak boleh 0 meja
        if ($current <= 1) {
            echo json_encode(['status' => 'error', 'message' => 'Minimal harus ada 1 meja!']); 
            return;
        }
        
        // Validasi 2: Cek meja terakhir lagi dipake gak?
        // Kita cek meja dengan nomor $current (meja terakhir)
        if ($this->Order_model->is_table_occupied($current)) {
            echo json_encode(['status' => 'error', 'message' => 'Gagal! Meja ' . $current . ' sedang terisi. Kosongkan dulu sebelum dihapus.']); 
            return;
        }

        $new_total = $current - 1;
        if($this->Settings_model->update_setting('total_tables', $new_total)) {
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error']);
        }
    }

    // --- FUNGSI: Kosongkan Meja ---
    // Mengubah status pesanan di meja menjadi 'finished' (admin/cashier).
    public function clear_table_action() {
        $this->_check_access(['admin', 'cashier']);
        $table = $this->input->post('table_number');
        if($this->Order_model->force_clear_table($table)) {
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error']);
        }
    }
    
    // --- FUNGSI: Halaman Stok & Menu ---
    // Menampilkan laporan stok produk dan kontrol untuk admin.
    public function stok() {
        $this->_check_access(['admin']);
        $data['stok_barang'] = $this->Product_model->get_all_products();
        $data['total_item'] = count($data['stok_barang']);
        $data['stok_kritis'] = 0;
        foreach ($data['stok_barang'] as $item) { if ($item->stock <= 10) $data['stok_kritis']++; }
        $this->_render('stok_view', 'Perhitungan Stok', $data);
    }

    // --- FUNGSI: Halaman Daftar Pesanan ---
    // Menampilkan pesanan dengan filter status dan tanggal (admin/cashier).
    public function pesanan() {
        $this->_check_access(['admin', 'cashier']);
        $date = $this->input->get('date');
        $status = $this->input->get('status');
        if (!$status) $status = 'process';

        $orders = $this->Order_model->get_filtered_orders($date, $status);
        $data['pesanan'] = [];
        foreach ($orders as $o) {
            $data['pesanan'][] = (object)[
                'id' => $o->id, 'order_number' => $o->order_number, 'meja' => $o->table_number,
                'menu' => $this->Order_model->get_order_items_summary($o->id),
                'status' => ucfirst($o->status), 'waktu' => date('H:i', strtotime($o->created_at)),
                'note' => $o->kitchen_note, 'total_price' => $o->total_price
            ];
        }
        $data['filter_date'] = $date;
        $data['filter_status'] = $status;
        $this->_render('pesanan_view', 'Daftar Pesanan', $data);
    }

    // --- FUNGSI: Halaman Riwayat & Income ---
    // Menampilkan laporan pendapatan dan riwayat pesanan (hanya admin).
    public function riwayat() {
        $this->_check_access(['admin']);
        $filter_date = $this->input->get('date');
        $data['income_today'] = $this->Order_model->get_income_today();
        $data['income_weekly'] = $this->Order_model->get_income_weekly();
        $data['income_monthly'] = $this->Order_model->get_income_monthly();

        if ($filter_date) {
            $data['income_filtered'] = $this->Order_model->get_income_by_date($filter_date);
            $data['riwayat'] = $this->Order_model->get_filtered_orders($filter_date, null);
        } else {
            $data['income_filtered'] = null;
            $data['riwayat'] = $this->Order_model->get_all_orders();
        }
        $data['filter_date'] = $filter_date;
        $this->_render('riwayat_view', 'Riwayat Transaksi', $data);
    }

    // --- FUNGSI: Halaman Self Service ---
    // Menampilkan menu untuk pelanggan memesan sendiri (admin/guest).
    public function self_service() {
        $this->_check_access(['admin', 'guest']);
        $raw = $this->Product_model->get_all_products();
        $filtered = [];
        foreach($raw as $p) {
            $filtered[] = ['id'=>$p->id, 'name'=>$p->name, 'price'=>$p->price, 'category'=>$p->category, 'image_url'=>$p->image_url, 'stock'=>$p->stock];
        }
        $data['menu_makanan'] = json_decode(json_encode($filtered));
        $this->_render('self_service_view', 'Menu Restoran', $data);
    }

    // --- FUNGSI: Halaman Kelola Pengguna ---
    // Menampilkan daftar semua pengguna untuk admin.
    public function users() {
        $this->_check_access(['admin']);
        $data['all_users'] = $this->User_model->get_all_users();
        $this->_render('users_view', 'Kelola Pengguna', $data);
    }

    public function check_table_status() {
        $table = $this->input->post('table_number', TRUE);
        $is_occupied = $this->Order_model->is_table_occupied($table);
        echo json_encode(['occupied' => $is_occupied]);
    }

    public function process_checkout() {
        $json = file_get_contents('php://input');
        $req = json_decode($json, true);
        if (!$req) { echo json_encode(['status'=>'error']); return; }

        if($req['table_number'] !== 'TAKEAWAY' && $this->Order_model->is_table_occupied($req['table_number'])) {
            echo json_encode(['status'=>'error', 'message' => 'Meja ini baru saja terisi!']); return;
        }

        $order_data = [
            'order_number' => '#ORD-' . rand(1000, 9999),
            'table_number' => strip_tags($req['table_number']),
            'total_price' => $req['total_price'],
            'payment_method' => $req['payment_method'],
            'kitchen_note' => strip_tags($req['note']),
            'status' => 'pending'
        ];
        $items = [];
        foreach ($req['items'] as $item) {
            $items[] = ['product_id'=>$item['id'], 'product_name'=>$item['name'], 'qty'=>$item['qty'], 'price'=>$item['price'], 'subtotal'=>$item['qty']*$item['price']];
        }
        if ($this->Order_model->create_order($order_data, $items)) echo json_encode(['status'=>'success', 'order_number'=>$order_data['order_number']]);
        else echo json_encode(['status'=>'error']);
    }

    // --- FUNGSI: Ubah Role Pengguna ---
    // Mengubah role pengguna (hanya admin, tidak bisa ubah diri sendiri).
    public function change_role() {
        $this->_check_access(['admin']);
        $user_id = $this->input->post('user_id', TRUE);
        $new_role = $this->input->post('role', TRUE);
        if ($user_id == $this->session->userdata('user_id')) { echo "<script>alert('Gagal!'); window.location.href='".site_url('dashboard/users')."';</script>"; return; }
        $this->User_model->update_role($user_id, $new_role);
        redirect('dashboard/users');
    }

    // --- FUNGSI: Simpan Produk ---
    // Menambah atau mengupdate data produk (hanya admin).
    public function save_product() {
        if ($this->session->userdata('role') !== 'admin') { echo json_encode(['status'=>'error']); return; }
        $id = $this->input->post('id', TRUE);
        $data = ['name'=>$this->input->post('name',TRUE), 'category'=>$this->input->post('category',TRUE), 'price'=>$this->input->post('price',TRUE), 'stock'=>$this->input->post('stock',TRUE), 'image_url'=>$this->input->post('image_url',TRUE)];
        $status = empty($id) ? $this->Product_model->insert($data) : $this->Product_model->update($id, $data);
        echo json_encode(['status'=>$status?'success':'error']);
    }

    // --- FUNGSI: Hapus Produk ---
    // Menghapus produk dari database (hanya admin).
    public function delete_product() {
        if ($this->session->userdata('role') !== 'admin') return;
        $id = $this->input->post('id', TRUE);
        echo json_encode(['status'=> $this->Product_model->delete($id)?'success':'error']);
    }

    // --- FUNGSI: Detail Pesanan ---
    // Mengambil detail pesanan dan itemnya untuk modal.
    public function get_order_detail($id) {
        echo json_encode(['order'=>$this->Order_model->get_order_by_id($id), 'items'=>$this->Order_model->get_order_items($id)]);
    }

    // --- FUNGSI: Selesaikan Pesanan ---
    // Mengubah status pesanan menjadi 'served' (sudah disajikan).
    public function complete_order() {
        $id = $this->input->post('id', TRUE);
        echo json_encode(['status'=> $this->Order_model->update_status($id, 'served')?'success':'error']);
    }

    private function _check_access($allowed) { if(!in_array($this->session->userdata('role'), $allowed)) redirect('dashboard'); }
    
    private function _render($view, $title, $data=[]) { 
        $data['page_title'] = $title;
        $data['content'] = $view;
        $data['role'] = $this->session->userdata('role');
        $data['name'] = $this->session->userdata('name');
        $data['email'] = $this->session->userdata('email');
        $this->load->view('dashboard_view', $data); 
    }
}