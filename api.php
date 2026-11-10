<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/config.php';

/** Klien HTTP tipis untuk PostgREST milik Supabase. */
final class SupabaseRest
{
    public function __construct(private string $url, private string $key) {}

    public function request(string $method, string $table, array $query = [], ?array $body = null): array
    {
        $url = rtrim($this->url, '/') . '/rest/v1/' . $table . ($query ? '?' . http_build_query($query) : '');
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'apikey: ' . $this->key,
                'Authorization: Bearer ' . $this->key,
                'Content-Type: application/json',
                'Prefer: return=representation',
            ],
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        $res = curl_exec($ch);
        if ($res === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('Tidak bisa menghubungi Supabase: ' . $err, 502);
        }
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $data = json_decode($res, true) ?? [];
        if ($code >= 400) {
            throw new RuntimeException($data['message'] ?? 'Permintaan ditolak Supabase', $code);
        }
        return $data;
    }
}

/** Logika bisnis bengkel. Semua input divalidasi di sini. */
final class BengkelApi
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
    private const RIWAYAT_SELECT = '*,kendaraan(plat_nomor,jenis,merek,model,pelanggan(nama)),detail_transaksi(subtotal,layanan_servis(nama_layanan,kategori))';

    public function __construct(private SupabaseRest $db) {}

    private function uuid(mixed $v): string
    {
        if (!is_string($v) || !preg_match(self::UUID, $v)) {
            throw new InvalidArgumentException('ID tidak valid', 422);
        }
        return strtolower($v);
    }

    private function teks(array $in, string $k, bool $wajib = false): ?string
    {
        $v = trim((string) ($in[$k] ?? ''));
        if ($v === '' && $wajib) {
            throw new InvalidArgumentException("Kolom '$k' wajib diisi", 422);
        }
        return $v === '' ? null : $v;
    }

    private function pilih(array $in, string $k, array $boleh): string
    {
        $v = $in[$k] ?? '';
        if (!in_array($v, $boleh, true)) {
            throw new InvalidArgumentException("Nilai '$k' tidak valid", 422);
        }
        return $v;
    }

    public function handle(string $method, string $route, array $in): array
    {
        return match ("$method $route") {
            'GET ping'        => $this->ping(),
            'GET kendaraan'   => $this->db->request('GET', 'kendaraan', ['select' => '*,pelanggan(nama,telepon)', 'order' => 'plat_nomor']),
            'POST pelanggan'  => $this->daftar($in),
            'GET layanan'     => $this->layanan($in),
            'POST layanan'    => $this->tambahLayanan($in),
            'DELETE layanan'  => $this->db->request('DELETE', 'layanan_servis', ['id' => 'eq.' . $this->uuid($in['id'] ?? null)]),
            'GET transaksi'   => $this->db->request('GET', 'transaksi', ['select' => self::RIWAYAT_SELECT, 'order' => 'created_at.desc', 'limit' => 50]),
            'POST transaksi'  => $this->simpanTransaksi($in),
            'POST status'     => $this->ubahStatus($in),
            default           => throw new RuntimeException('Rute tidak ditemukan', 404),
        };
    }

    private function ping(): array
    {
        $this->db->request('GET', 'pelanggan', ['select' => 'id', 'limit' => 1]);
        return ['ok' => true];
    }

    private function daftar(array $in): array
    {
        $plat  = strtoupper($this->teks($in, 'plat_nomor', true));
        $jenis = $this->pilih($in, 'jenis', ['Motor', 'Mobil']);
        $p = $this->db->request('POST', 'pelanggan', [], [[
            'nama' => $this->teks($in, 'nama', true), 'telepon' => $this->teks($in, 'telepon'), 'alamat' => $this->teks($in, 'alamat'),
        ]])[0];
        try {
            return $this->db->request('POST', 'kendaraan', [], [[
                'pelanggan_id' => $p['id'], 'plat_nomor' => $plat, 'jenis' => $jenis,
                'merek' => $this->teks($in, 'merek'), 'model' => $this->teks($in, 'model'),
            ]]);
        } catch (Throwable $e) {
            $this->db->request('DELETE', 'pelanggan', ['id' => 'eq.' . $p['id']]); // batalkan pelanggan yatim
            throw $e;
        }
    }

    /** Filter otomatis: ?jenis=Motor => hanya Motor + Semua. */
    private function layanan(array $in): array
    {
        $q = ['select' => '*', 'order' => 'kategori,nama_layanan'];
        if (!empty($in['jenis'])) {
            $j = $this->pilih($in, 'jenis', ['Motor', 'Mobil']);
            $q['jenis_kendaraan'] = "in.($j,Semua)";
        }
        return $this->db->request('GET', 'layanan_servis', $q);
    }

    private function tambahLayanan(array $in): array
    {
        $harga = $in['harga'] ?? null;
        if (!is_numeric($harga) || $harga < 0) {
            throw new InvalidArgumentException('Harga tidak valid', 422);
        }
        return $this->db->request('POST', 'layanan_servis', [], [[
            'nama_layanan'    => $this->teks($in, 'nama_layanan', true),
            'kategori'        => $this->pilih($in, 'kategori', ['Jasa', 'Sparepart']),
            'jenis_kendaraan' => $this->pilih($in, 'jenis_kendaraan', ['Motor', 'Mobil', 'Semua']),
            'harga'           => (float) $harga,
        ]]);
    }

    /** Total dihitung ulang di server dari harga di database, bukan dari angka kiriman browser. */
    private function simpanTransaksi(array $in): array
    {
        $kid     = $this->uuid($in['kendaraan_id'] ?? null);
        $mekanik = $this->teks($in, 'mekanik', true);
        $ids     = array_map(fn($i) => $this->uuid($i), (array) ($in['layanan_ids'] ?? []));
        if (!$ids) {
            throw new InvalidArgumentException('Tambahkan minimal satu layanan', 422);
        }
        $k = $this->db->request('GET', 'kendaraan', ['select' => 'id,jenis', 'id' => "eq.$kid"])[0]
            ?? throw new RuntimeException('Kendaraan tidak ditemukan', 404);

        $rows = $this->db->request('GET', 'layanan_servis', [
            'select' => 'id,harga,jenis_kendaraan',
            'id'     => 'in.(' . implode(',', array_unique($ids)) . ')',
        ]);
        $harga = [];
        foreach ($rows as $r) {
            if (!in_array($r['jenis_kendaraan'], [$k['jenis'], 'Semua'], true)) {
                throw new InvalidArgumentException('Ada layanan yang tidak cocok untuk jenis kendaraan ini', 422);
            }
            $harga[$r['id']] = (float) $r['harga'];
        }
        $total = 0.0;
        foreach ($ids as $id) {
            if (!isset($harga[$id])) {
                throw new InvalidArgumentException('Layanan tidak ditemukan', 422);
            }
            $total += $harga[$id];
        }

        $t = $this->db->request('POST', 'transaksi', [], [[
            'kendaraan_id' => $kid, 'mekanik_nama' => $mekanik, 'total_biaya' => $total, 'status' => 'Pending',
        ]])[0];
        try {
            $this->db->request('POST', 'detail_transaksi', [], array_map(
                fn($id) => ['transaksi_id' => $t['id'], 'layanan_id' => $id, 'subtotal' => $harga[$id]], $ids
            ));
        } catch (Throwable $e) {
            $this->db->request('DELETE', 'transaksi', ['id' => 'eq.' . $t['id']]);
            throw $e;
        }
        return $t;
    }

    private function ubahStatus(array $in): array
    {
        return $this->db->request('PATCH', 'transaksi',
            ['id' => 'eq.' . $this->uuid($in['id'] ?? null)],
            ['status' => $this->pilih($in, 'status', ['Pending', 'Proses', 'Selesai'])]);
    }
}

/* ---- Router ---- */
try {
    $body = json_decode(file_get_contents('php://input') ?: '[]', true) ?: [];
    $api  = new BengkelApi(new SupabaseRest(SUPABASE_URL, SUPABASE_KEY));
    $out  = $api->handle($_SERVER['REQUEST_METHOD'], (string) ($_GET['r'] ?? ''), $body + $_GET);
    echo json_encode($out);
} catch (Throwable $e) {
    $code = $e->getCode();
    http_response_code($code >= 400 && $code < 600 ? $code : 500);
    echo json_encode(['error' => $e->getMessage()]);
}
