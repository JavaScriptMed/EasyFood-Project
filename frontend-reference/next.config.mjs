/** @type {import('next').NextConfig} */
const nextConfig = {
  // Las fotos de platillos vienen del backend (https://api.tu-dominio.com/storage/...): se muestran tal cual, sin el optimizador de imágenes.
  images: {
    unoptimized: true,
  },
}

export default nextConfig
