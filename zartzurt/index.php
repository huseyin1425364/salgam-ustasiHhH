<!DOCTYPE html>
<html lang="tr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Şalgammı? 🍷</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@700;900&family=Plus+Jakarta+Sans:wght@400;600;800&display=swap" rel="stylesheet">
  <style>
    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
      user-select: none;
    }

    body, html {
      width: 100%;
      height: 100%;
      overflow: hidden;
      background-color: #050106;
      font-family: 'Plus Jakarta Sans', sans-serif;
      color: #fff;
    }

    /* Arka plan ışık küreleri */
    .bg-glow {
      position: absolute;
      width: 500px;
      height: 500px;
      border-radius: 50%;
      filter: blur(120px);
      opacity: 0.45;
      pointer-events: none;
      z-index: 1;
      animation: pulse 8s ease-in-out infinite alternate;
    }
    .glow-1 {
      top: -100px;
      left: 15%;
      background: #7a0026;
    }
    .glow-2 {
      bottom: -100px;
      right: 15%;
      background: #c2185b;
      animation-delay: -4s;
    }

    @keyframes pulse {
      0% { transform: scale(0.9) translate(0, 0); }
      100% { transform: scale(1.2) translate(30px, -20px); }
    }

    /* İlk Ekran - Lüks Karşılama Kartı */
    #landing-screen {
      position: relative;
      z-index: 10;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      width: 100vw;
      height: 100vh;
      background: radial-gradient(circle at center, rgba(30, 2, 12, 0.6) 0%, rgba(5, 1, 6, 0.95) 100%);
      transition: opacity 0.8s cubic-bezier(0.16, 1, 0.3, 1), transform 0.8s cubic-bezier(0.16, 1, 0.3, 1);
    }

    .glass-card {
      background: rgba(255, 255, 255, 0.03);
      border: 1px solid rgba(255, 180, 200, 0.15);
      box-shadow: 0 30px 60px -15px rgba(0, 0, 0, 0.8),
                  0 0 40px -10px rgba(186, 24, 75, 0.3),
                  inset 0 1px 0 rgba(255, 255, 255, 0.15);
      backdrop-filter: blur(25px);
      -webkit-backdrop-filter: blur(25px);
      padding: 70px 90px;
      border-radius: 36px;
      text-align: center;
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 40px;
    }

    .title {
      font-family: 'Cinzel', serif;
      font-size: clamp(3rem, 7vw, 5.5rem);
      font-weight: 900;
      letter-spacing: 2px;
      background: linear-gradient(135deg, #ffffff 20%, #f48fb1 60%, #ad1457 100%);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      text-shadow: 0 10px 30px rgba(173, 20, 87, 0.4);
    }

    .btn-salgam {
      position: relative;
      cursor: pointer;
      font-family: 'Plus Jakarta Sans', sans-serif;
      font-size: 1.35rem;
      font-weight: 800;
      letter-spacing: 1.5px;
      text-transform: uppercase;
      color: #ffffff;
      background: linear-gradient(135deg, #9b0033 0%, #d81b60 100%);
      border: none;
      padding: 22px 65px;
      border-radius: 100px;
      box-shadow: 0 15px 35px -5px rgba(216, 27, 96, 0.5),
                  inset 0 1px 1px rgba(255, 255, 255, 0.4);
      transition: all 0.3s cubic-bezier(0.2, 0, 0.2, 1);
      overflow: hidden;
    }

    .btn-salgam::after {
      content: '';
      position: absolute;
      top: -50%;
      left: -60%;
      width: 40px;
      height: 200%;
      background: rgba(255, 255, 255, 0.4);
      transform: rotate(35deg);
      transition: all 0.7s ease;
    }

    .btn-salgam:hover {
      transform: translateY(-4px) scale(1.04);
      box-shadow: 0 25px 45px -5px rgba(216, 27, 96, 0.7),
                  0 0 25px 5px rgba(255, 64, 129, 0.4),
                  inset 0 1px 1px rgba(255, 255, 255, 0.6);
    }

    .btn-salgam:hover::after {
      left: 140%;
    }

    .btn-salgam:active {
      transform: translateY(1px) scale(0.98);
    }

    /* Video Katmanı (Troll Modu) */
    #video-wrapper {
      position: fixed;
      top: 0;
      left: 0;
      width: 100vw;
      height: 100vh;
      background-color: #000;
      z-index: 100;
      opacity: 0;
      pointer-events: none;
      transition: opacity 0.5s ease;
    }

    #video-wrapper.active {
      opacity: 1;
      pointer-events: all;
    }

    video {
      width: 100vw;
      height: 100vh;
      object-fit: cover;
      outline: none;
      border: none;
    }

    /* Durdurulamazlık Önlemi: Videonun üstüne görünmez kalkan */
    .video-shield {
      position: absolute;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      z-index: 101;
      background: transparent;
      cursor: none; /* Fare imlecini yok eder */
    }
  </style>
</head>
<body>

  <div class="bg-glow glow-1"></div>
  <div class="bg-glow glow-2"></div>

  <!-- AŞAMA 1: Giriş Tasarımı -->
  <main id="landing-screen">
    <div class="glass-card">
      <h1 class="title">Şalgammı?</h1>
      <button class="btn-salgam" id="trigger-btn">Şalgam</button>
    </div>
  </main>

  <!-- AŞAMA 2: Tam Ekran Troll Video Katmanı -->
  <div id="video-wrapper">
    <div class="video-shield" id="shield"></div>
    <!-- 
      Aşağıdaki 'src' alanına kendi video dosyanızın adını/yolunu yazın (örn: video.mp4).
      Şu an test için açık kaynaklı bir video URL'si eklenmiştir.
    -->
    <video id="troll-video" loop playsinline preload="auto">
      <source src="as.mp4" type="video/mp4">
      Tarayıcınız video etiketini desteklemiyor.
    </video>
  </div>

  <script>
    const landingScreen = document.getElementById('landing-screen');
    const videoWrapper = document.getElementById('video-wrapper');
    const video = document.getElementById('troll-video');
    const triggerBtn = document.getElementById('trigger-btn');
    const shield = document.getElementById('shield');

    triggerBtn.addEventListener('click', async () => {
      // 1. Ekranı Gizle
      landingScreen.style.opacity = '0';
      landingScreen.style.transform = 'scale(0.92)';

      setTimeout(async () => {
        landingScreen.style.display = 'none';
        videoWrapper.classList.add('active');

        // 2. Tam Ekrana Zorla
        const docEl = document.documentElement;
        if (docEl.requestFullscreen) {
          docEl.requestFullscreen().catch(() => {});
        } else if (docEl.webkitRequestFullscreen) {
          docEl.webkitRequestFullscreen();
        } else if (docEl.msRequestFullscreen) {
          docEl.msRequestFullscreen();
        }

        // 3. Videoyu Sesiyle Birlikte Başlat
        video.muted = false;
        video.currentTime = 0;
        try {
          await video.play();
        } catch (err) {
          // Tarayıcı sesli otomatik oynatmaya izin vermezse sessiz başlatıp anında sesi açar
          video.muted = true;
          await video.play();
          video.muted = false;
        }
      }, 400);
    });

    // 4. Durdurulamama Önlemleri (Troll Koruması)
    
    // Tıklamayla duraklatmayı engelle
    shield.addEventListener('click', (e) => {
      e.preventDefault();
      e.stopPropagation();
      video.play();
    });

    // Sağ tık menüsünü engelle
    window.addEventListener('contextmenu', (e) => e.preventDefault());

    // Boşluk (Space), K, Esc, Sol/Sağ ok tuşlarıyla videoyu durdurmayı veya sarmayı engelle
    window.addEventListener('keydown', (e) => {
      const blockedKeys = ['Space', 'KeyK', 'ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'];
      if (blockedKeys.includes(e.code) || e.keyCode === 32) {
        e.preventDefault();
        video.play();
      }
    });

    // Video herhangi bir nedenle pause olursa anında tekrar oynat
    video.addEventListener('pause', () => {
      video.play();
    });
  </script>
</body>
</html>