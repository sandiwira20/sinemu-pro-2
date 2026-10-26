<?php

namespace App\Http\Resources\Api;

use App\Http\Resources\Api\Concerns\FormatsApiValues;
use App\Services\User\Claims\ClaimInstructionService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class KlaimResource extends JsonResource
{
    use FormatsApiValues;

    /**
     * Mengubah data klaim menjadi response JSON API.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $instruction = app(ClaimInstructionService::class)
            ->buildInstructionData($this->resource);

        // Mengambil data barang, pengguna, dan kategori.
        $barang = $instruction['barang'] ?? null;
        $user = $instruction['user'] ?? null;
        $kategori = $barang?->kategori;

        return [
            // Data klaim
            'id' => (int) $this->id,

            'barang_id' => $this->barang_id !== null
                ? (int) $this->barang_id
                : null,

            'nama_barang' => (string) (
                $barang?->nama_barang
                ?? $this->laporanHilang?->nama_barang
                ?? 'Klaim Barang'
            ),

            // Informasi pemilik/pengaju klaim
            'nama_pengambil' => (string) ($user?->name ?? ''),
            'email_pengambil' => (string) ($user?->email ?? ''),

            'status' => (string) $this->status_klaim,
            'alasan' => $this->catatan,

            'kontak' => $this->kontak
                ?? $this->laporanHilang?->kontak_pelapor
                ?? $user?->nomor_telepon,

            'tanggal' => $this->formatDateValue($this->created_at),

            // Rincian barang temuan
            'kategori_barang' => (string) (
                $kategori?->nama_kategori
                ?? $kategori?->nama
                ?? ''
            ),

            'lokasi_ditemukan' => (string) (
                $barang?->lokasi
                ?? $barang?->lokasi_ditemukan
                ?? ''
            ),

            'tanggal_temuan' => $this->formatDateValue(
                $barang?->tanggal_ditemukan
                    ?? $barang?->created_at
            ),

            'deskripsi_barang' => (string) (
                $barang?->deskripsi
                ?? $barang?->keterangan
                ?? ''
            ),

            'warna_dominan' => (string) (
                $barang?->warna_dominan ?? ''
            ),

            'merek' => (string) (
                $barang?->merek
                ?? $barang?->merk
                ?? ''
            ),

            'nomor_seri' => (string) (
                $barang?->nomor_seri ?? ''
            ),

            // Panduan pengambilan
            'kode_pengambilan' => $instruction['claimCode'],
            'status_text' => $instruction['statusMeta']['text'],
            'status_description' => $instruction['statusMeta']['description'],
            'siap_diambil' => $instruction['isReadyForPickup'],
            'selesai' => $instruction['isCompleted'],

            // Foto barang
            'foto_barang' => $instruction['itemImageUrl'],

            // Informasi posko
            'nama_posko' => $instruction['poskoName'],
            'alamat_posko' => $instruction['poskoAddress'],
            'wilayah_posko' => $instruction['poskoWilayah'],
            'jam_layanan' => $instruction['jamLayanan'],
            'penanggung_jawab' => $instruction['penanggungJawab'],
            'catatan_posko' => $instruction['catatanPosko'],

            // Kontak petugas
            'kontak_posko' => $instruction['contactRaw'],
            'wa_url' => $instruction['waUrl'],
            'tel_href' => $instruction['telHref'],
            'email_admin' => $instruction['adminEmail'],
        ];
    }
}
