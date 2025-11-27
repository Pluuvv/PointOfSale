<?php
defined('BASEPATH') OR exit('No direct script access allowed');

// --- MODEL: Order_model ---
// Bertanggung jawab untuk mengelola data pesanan di database.
// Menangani transaksi pembuatan pesanan, filter pesanan berdasarkan status dan tanggal,
// pengelolaan status meja, serta statistik pendapatan.

class Order_model extends CI_Model {

    // --- Fungsi: create_order ---
    // Membuat pesanan baru dengan transaksi database.
    // Input: $order_data (array data pesanan), $items_data (array item pesanan).
    // Output: boolean status transaksi.
    // Tujuan: Menyimpan pesanan dan itemnya, lalu mengurangi stok produk.
    public function create_order($order_data, $items_data) {
        $this->db->trans_start();
        $this->db->insert('orders', $order_data);
        $order_id = $this->db->insert_id();
        foreach ($items_data as &$item) { $item['order_id'] = $order_id; }
        $this->db->insert_batch('order_items', $items_data);
        foreach ($items_data as $item) {
            $this->db->set('stock', 'stock - ' . $item['qty'], FALSE);
            $this->db->where('id', $item['product_id']);
            $this->db->update('products');
        }
        $this->db->trans_complete();
        return $this->db->trans_status();
    }

    // --- Fungsi: get_filtered_orders ---
    // Mengambil pesanan yang difilter berdasarkan tanggal dan status.
    // Input: $date (string tanggal opsional), $status (string status opsional).
    // Output: array objek pesanan.
    // Tujuan: Menampilkan pesanan sesuai filter untuk manajemen.
    public function get_filtered_orders($date = null, $status = null) {
        $this->db->order_by('created_at', 'DESC');

        if ($date) {
            $this->db->where('DATE(created_at)', $date);
        }

        if ($status) {
            if ($status === 'process') {
                // Tab "Dalam Proses" -> Cuma tampilkan yang belum disajikan
                $this->db->where_in('status', ['pending', 'cooking', 'ready']);
            }
            elseif ($status === 'served') {
                // Tab "Riwayat Selesai" -> Termasuk yang sedang makan (served) DAN yang sudah pulang (finished)
                $this->db->where_in('status', ['served', 'finished']);
            }
            elseif ($status !== 'all') {
                $this->db->where('status', $status);
            }
        }

        return $this->db->get('orders')->result();
    }

    // --- Fungsi: get_busy_tables ---
    // Mengambil nomor meja yang sedang digunakan.
    // Input: none.
    // Output: array nomor meja.
    // Tujuan: Menentukan meja yang tidak tersedia untuk pesanan baru.
    public function get_busy_tables() {
        $this->db->distinct();
        $this->db->select('table_number');
        $this->db->where_in('status', ['pending', 'cooking', 'ready', 'served']);
        $this->db->where('table_number !=', 'TAKEAWAY');
        $query = $this->db->get('orders');
        $busy = []; foreach($query->result() as $row) { $busy[] = $row->table_number; }
        return $busy;
    }

    // --- Fungsi: force_clear_table ---
    // Memaksa mengosongkan meja dengan mengubah status pesanan menjadi finished.
    // Input: $table_number (string nomor meja).
    // Output: boolean hasil update.
    // Tujuan: Mengatasi situasi darurat atau kesalahan sistem.
    public function force_clear_table($table_number) {
        $this->db->where('table_number', $table_number);
        $this->db->where_in('status', ['pending', 'cooking', 'ready', 'served']);
        return $this->db->update('orders', ['status' => 'finished']);
    }

    // --- Fungsi: is_table_occupied ---
    // Mengecek apakah meja sedang digunakan.
    // Input: $table_number (string nomor meja).
    // Output: boolean true jika digunakan.
    // Tujuan: Validasi sebelum membuat pesanan baru.
    public function is_table_occupied($table_number) {
        if($table_number == 'TAKEAWAY') return false;
        $this->db->where('table_number', $table_number);
        $this->db->where_in('status', ['pending', 'cooking', 'ready', 'served']);
        return $this->db->count_all_results('orders') > 0;
    }

    // Helpers

    // --- Fungsi: get_all_orders ---
    // Mengambil semua pesanan tanpa filter.
    // Input: none.
    // Output: array objek pesanan.
    public function get_all_orders() { return $this->get_filtered_orders(); }

    // --- Fungsi: get_order_by_id ---
    // Mengambil pesanan berdasarkan ID.
    // Input: $id (int ID pesanan).
    // Output: objek pesanan atau null.
    public function get_order_by_id($id) { return $this->db->get_where('orders', ['id' => $id])->row(); }

    // --- Fungsi: get_order_items ---
    // Mengambil item pesanan berdasarkan ID pesanan.
    // Input: $order_id (int ID pesanan).
    // Output: array objek item.
    public function get_order_items($order_id) { return $this->db->get_where('order_items', ['order_id' => $order_id])->result(); }

    // --- Fungsi: get_order_items_summary ---
    // Membuat ringkasan item pesanan dalam format string.
    // Input: $order_id (int ID pesanan).
    // Output: string ringkasan.
    // Tujuan: Untuk tampilan cepat di daftar pesanan.
    public function get_order_items_summary($order_id) {
        $this->db->select('product_name, qty');
        $items = $this->db->get_where('order_items', ['order_id' => $order_id])->result();
        $s = []; foreach ($items as $i) $s[] = $i->qty.'x '.$i->product_name;
        return implode(', ', $s);
    }

    // --- Fungsi: update_status ---
    // Mengupdate status pesanan.
    // Input: $order_id (int ID pesanan), $status (string status baru).
    // Output: boolean hasil update.
    public function update_status($order_id, $status) {
        $this->db->where('id', $order_id);
        return $this->db->update('orders', ['status' => $status]);
    }

    // Statistik

    // --- Fungsi: get_income_today ---
    // Menghitung total pendapatan hari ini.
    // Input: none.
    // Output: float total pendapatan.
    public function get_income_today() { 
        $this->db->select_sum('total_price'); 
        $this->db->where('DATE(created_at)', date('Y-m-d')); 
        $this->db->where_in('status', ['served', 'finished']); 
        return $this->db->get('orders')->row()->total_price ?? 0; 
    }

    // --- Fungsi: get_income_weekly ---
    // Menghitung total pendapatan minggu ini.
    // Input: none.
    // Output: float total pendapatan.
    public function get_income_weekly() { 
        $this->db->select_sum('total_price'); 
        $this->db->where('YEARWEEK(created_at, 1) = YEARWEEK(CURDATE(), 1)'); 
        $this->db->where_in('status', ['served', 'finished']);
        return $this->db->get('orders')->row()->total_price ?? 0; 
    }
    // --- Fungsi: get_income_monthly ---
    // Menghitung total pendapatan bulan ini.
    // Input: none.
    // Output: float total pendapatan.
    public function get_income_monthly() { 
        $this->db->select_sum('total_price'); 
        $this->db->where('MONTH(created_at)', date('m')); 
        $this->db->where('YEAR(created_at)', date('Y')); 
        $this->db->where_in('status', ['served', 'finished']);
        return $this->db->get('orders')->row()->total_price ?? 0; 
    }

    // --- Fungsi: get_income_by_date ---
    // Menghitung total pendapatan berdasarkan tanggal tertentu.
    // Input: $d (string tanggal).
    // Output: float total pendapatan.
    public function get_income_by_date($date) { 
        $this->db->select_sum('total_price'); 
        $this->db->where('DATE(created_at)', $date); 
        $this->db->where_in('status', ['served', 'finished']);
        return $this->db->get('orders')->row()->total_price ?? 0; 
    }

        // Fungsi: Hitung Pendapatan Semua Waktu (All Time)
    public function get_income_all_time() { 
        $this->db->select_sum('total_price'); 
        // Hitung semua yang statusnya 'served' atau 'finished' tanpa filter tanggal
        $this->db->where_in('status', ['served', 'finished']);
        return $this->db->get('orders')->row()->total_price ?? 0; 
    }

}
