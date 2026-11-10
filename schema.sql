-- Sistem Manajemen Bengkel: jalankan di Supabase SQL Editor
-- gen_random_uuid() sudah tersedia di Supabase (pgcrypto/PG13+)

create table if not exists pelanggan (
  id uuid primary key default gen_random_uuid(),
  nama text not null,
  telepon text,
  alamat text,
  created_at timestamp with time zone default now()
);

create table if not exists kendaraan (
  id uuid primary key default gen_random_uuid(),
  pelanggan_id uuid not null references pelanggan(id) on delete cascade,
  plat_nomor text not null unique,
  jenis text not null check (jenis in ('Mobil', 'Motor')),
  merek text,
  model text
);

create table if not exists layanan_servis (
  id uuid primary key default gen_random_uuid(),
  nama_layanan text not null,
  jenis_kendaraan text not null check (jenis_kendaraan in ('Motor', 'Mobil', 'Semua')),
  kategori text not null check (kategori in ('Jasa', 'Sparepart')),
  harga numeric not null default 0 check (harga >= 0)
);

create table if not exists transaksi (
  id uuid primary key default gen_random_uuid(),
  kendaraan_id uuid not null references kendaraan(id) on delete restrict,
  mekanik_nama text not null,
  total_biaya numeric not null default 0,
  status text not null default 'Pending' check (status in ('Pending', 'Proses', 'Selesai')),
  created_at timestamp with time zone default now()
);

create table if not exists detail_transaksi (
  id uuid primary key default gen_random_uuid(),
  transaksi_id uuid not null references transaksi(id) on delete cascade,
  layanan_id uuid not null references layanan_servis(id) on delete restrict,
  subtotal numeric not null default 0
);

create index if not exists idx_kendaraan_pelanggan on kendaraan(pelanggan_id);
create index if not exists idx_transaksi_kendaraan on transaksi(kendaraan_id);
create index if not exists idx_detail_transaksi on detail_transaksi(transaksi_id);

-- Row Level Security: akses publik HANYA untuk pengujian awal.
-- Ganti dengan kebijakan berbasis auth.uid() sebelum produksi.
alter table pelanggan enable row level security;
alter table kendaraan enable row level security;
alter table layanan_servis enable row level security;
alter table transaksi enable row level security;
alter table detail_transaksi enable row level security;

do $$
declare t text;
begin
  foreach t in array array['pelanggan','kendaraan','layanan_servis','transaksi','detail_transaksi']
  loop
    execute format('drop policy if exists "public_all_%1$s" on %1$I', t);
    execute format(
      'create policy "public_all_%1$s" on %1$I for all to anon, authenticated using (true) with check (true)', t);
  end loop;
end $$;

-- Data contoh (opsional)
insert into layanan_servis (nama_layanan, jenis_kendaraan, kategori, harga) values
  ('Ganti oli mesin', 'Semua', 'Jasa', 35000),
  ('Tune up karburator/injeksi', 'Motor', 'Jasa', 75000),
  ('Ganti kampas rem depan', 'Motor', 'Sparepart', 55000),
  ('Spooring & balancing', 'Mobil', 'Jasa', 180000),
  ('Ganti aki mobil', 'Mobil', 'Sparepart', 950000),
  ('Oli mesin 1L', 'Semua', 'Sparepart', 65000);
