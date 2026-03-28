---
layout: home

hero:
  name: NukeVideo
  text: Video Processing & Delivery Engine
  tagline: Open-source, self-hosted core for uploading, encoding, and serving video. Integrate it into your own backend.
  actions:
    - theme: brand
      text: Get Started
      link: /guide/getting-started
    - theme: alt
      text: View on GitHub
      link: https://github.com/chikenare/nukevideo

features:
  - icon: 🎬
    title: Multi-Format Encoding
    details: Transcode videos into HLS, DASH, and MP4 using FFmpeg with customizable encoding templates.
  - icon: ⚡
    title: Distributed Processing
    details: Scale horizontally by adding worker nodes. NukeVideo automatically distributes encoding jobs based on workload.
  - icon: 📡
    title: Adaptive Bitrate Streaming
    details: Deliver video via Nginx VOD module with HLS and DASH support, token-based access control, and S3 storage.
  - icon: 🗄️
    title: S3-Compatible Storage
    details: Store originals and processed files in any S3-compatible service — AWS S3, MinIO, RustFS, and more.
  - icon: 📊
    title: Real-Time Monitoring
    details: Track encoding progress in real-time, monitor node health, and analyze bandwidth with ClickHouse analytics.
  - icon: 🔌
    title: RESTful API
    details: Full-featured API with Sanctum authentication, webhook support, and multipart uploads via Uppy.
---
