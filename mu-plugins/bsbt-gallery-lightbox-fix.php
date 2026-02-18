<?php
/**
 * Plugin Name: BSBT – Gallery Lightbox Fix (FINAL STABLE)
 * Description: Safe custom lightbox for MotoPress gallery with centered arrows, proper X, iOS glass UI, counter.
 * Author: BS Business Travelling
 */

if ( ! defined('ABSPATH') ) {
	exit;
}

add_action('wp_footer', function () {

	if ( is_admin() ) return;
	if ( ! is_singular('mphb_room_type') ) return;

	?>
	<style>
		:root{
			--bsbt-blue:#082567;
			--bsbt-gold:#E0B849;
		}

		/* ===============================
		   Overlay
		   =============================== */
		#bsbtLB{
			position:fixed;
			inset:0;
			background:rgba(0,0,0,.92);
			display:none;
			align-items:center;
			justify-content:center;
			z-index:999999;
		}
		#bsbtLB.bsbtLB--open{ display:flex; }

		#bsbtLB__img{
			max-width:96vw;
			max-height:92vh;
			border-radius:18px;
			box-shadow:0 20px 60px rgba(0,0,0,.65);
			user-select:none;
		}

		/* ===============================
		   iOS Glass Button Base
		   =============================== */
		.bsbtLB-btn{
			position:absolute;
			width:52px;
			height:52px;
			border-radius:999px;
			border:1px solid rgba(255,255,255,.35);
			cursor:pointer;
			padding:0;

			display:flex;
			align-items:center;
			justify-content:center;

			/* glass */
			background:
				linear-gradient(
					180deg,
					rgba(255,255,255,.65),
					rgba(255,255,255,.25)
				);

			backdrop-filter: blur(14px) saturate(1.4);
			-webkit-backdrop-filter: blur(14px) saturate(1.4);

			box-shadow:
				0 22px 44px rgba(0,0,0,.45),
				inset 0 2px 1px rgba(255,255,255,.85),
				inset 0 -8px 18px rgba(0,0,0,.22);

			transition:
				transform .2s cubic-bezier(.22,.61,.36,1),
				box-shadow .2s cubic-bezier(.22,.61,.36,1);

			font-size:0;
			line-height:0;
		}

		/* glass highlight */
		.bsbtLB-btn::after{
			content:"";
			position:absolute;
			top:3px;
			left:4px;
			right:4px;
			height:40%;
			border-radius:999px;
			background:linear-gradient(
				180deg,
				rgba(255,255,255,.9),
				rgba(255,255,255,0)
			);
			z-index:1;
			pointer-events:none;
		}

		.bsbtLB-btn:hover{
			transform:translateY(-2px) scale(1.05);
			box-shadow:
				0 30px 60px rgba(0,0,0,.55),
				inset 0 2px 1px rgba(255,255,255,.95),
				inset 0 -10px 22px rgba(0,0,0,.25);
		}

		.bsbtLB-btn:active{
			transform:translateY(1px) scale(.96);
		}

		/* ===============================
		   CLOSE (X) — PERFECT CENTER
		   =============================== */
		#bsbtLB__close{
			top:16px;
			right:16px;
		}

		#bsbtLB__close::before,
		#bsbtLB__close::after{
			content:"";
			position:absolute;
			top:50%;
			left:50%;
			width:22px;
			height:4px;
			border-radius:4px;
			background:linear-gradient(180deg,var(--bsbt-blue),#061e53);
			box-shadow:0 2px 0 rgba(0,0,0,.25);
			transform-origin:center;
			z-index:2;
		}

		#bsbtLB__close::before{
			transform:translate(-50%,-50%) rotate(45deg);
		}
		#bsbtLB__close::after{
			transform:translate(-50%,-50%) rotate(-45deg);
		}

		#bsbtLB__close:hover::before,
		#bsbtLB__close:hover::after{
			background:linear-gradient(180deg,var(--bsbt-gold),#caa33d);
		}

		/* ===============================
		   ARROWS — CENTERED & VISIBLE
		   =============================== */
		#bsbtLB__prev,
		#bsbtLB__next{
			top:50%;
			transform:translateY(-50%);
		}
		#bsbtLB__prev{ left:18px; }
		#bsbtLB__next{ right:18px; }

		#bsbtLB__prev::before,
		#bsbtLB__next::before{
			content:"";
			position:absolute;
			top:50%;
			left:50%;
			width:16px;
			height:16px;
			border-right:5px solid var(--bsbt-blue);
			border-bottom:5px solid var(--bsbt-blue);
			border-radius:2px;
			filter:drop-shadow(0 2px 0 rgba(0,0,0,.25));
			transform-origin:center;
			z-index:2;
			pointer-events:none;
		}

		#bsbtLB__prev::before{
			transform:translate(-50%,-50%) rotate(135deg);
		}
		#bsbtLB__next::before{
			transform:translate(-50%,-50%) rotate(-45deg);
		}

		#bsbtLB__prev:hover::before,
		#bsbtLB__next:hover::before{
			border-right-color:var(--bsbt-gold);
			border-bottom-color:var(--bsbt-gold);
		}

		/* ===============================
		   COUNTER
		   =============================== */
		#bsbtLB__counter{
			position:absolute;
			bottom:18px;
			left:50%;
			transform:translateX(-50%);
			padding:6px 14px;
			border-radius:999px;
			background:rgba(255,255,255,.9);
			font-family:system-ui,-apple-system,"Segoe UI",sans-serif;
			font-weight:700;
			font-size:13px;
			color:var(--bsbt-blue);
			box-shadow:0 10px 24px rgba(0,0,0,.4);
			user-select:none;
		}

		.bsbt-lb-clickable{ cursor:zoom-in!important; }

		@media(max-width:768px){
			.bsbtLB-btn{ width:46px; height:46px; }
		}
	</style>

	<div id="bsbtLB">
		<button id="bsbtLB__close" class="bsbtLB-btn" aria-label="Close"></button>
		<button id="bsbtLB__prev" class="bsbtLB-btn" aria-label="Previous"></button>

		<img id="bsbtLB__img" alt="">

		<button id="bsbtLB__next" class="bsbtLB-btn" aria-label="Next"></button>
		<div id="bsbtLB__counter">1 / 1</div>
	</div>

	<script>
	(function(){
		if(window.__bsbtLBLoaded) return;
		window.__bsbtLBLoaded = true;

		const lb=document.getElementById('bsbtLB');
		const img=document.getElementById('bsbtLB__img');
		const counter=document.getElementById('bsbtLB__counter');
		const prev=document.getElementById('bsbtLB__prev');
		const next=document.getElementById('bsbtLB__next');
		const close=document.getElementById('bsbtLB__close');

		let items=[],index=0;

		const getSrc=el=>{
			if(el.tagName==='IMG')
				return el.getAttribute('data-large_image')||el.currentSrc||el.src||'';
			const bg=getComputedStyle(el).backgroundImage;
			const m=bg&&bg.match(/url\(["']?(.+?)["']?\)/);
			return m?m[1]:'';
		};

		const openAt=i=>{
			index=(i+items.length)%items.length;
			img.src=items[index];
			counter.textContent=(index+1)+' / '+items.length;
			lb.classList.add('bsbtLB--open');
			document.body.style.overflow='hidden';
		};

		const closeLB=()=>{
			lb.classList.remove('bsbtLB--open');
			document.body.style.overflow='';
			img.src='';
		};

		prev.onclick=e=>{e.stopPropagation();openAt(index-1);};
		next.onclick=e=>{e.stopPropagation();openAt(index+1);};
		close.onclick=closeLB;

		document.addEventListener('keydown',e=>{
			if(!lb.classList.contains('bsbtLB--open'))return;
			if(e.key==='Escape')closeLB();
			if(e.key==='ArrowRight')openAt(index+1);
			if(e.key==='ArrowLeft')openAt(index-1);
		});

		const bind=()=>{
			const root=document.querySelector(
				'.mphb-room-type-gallery,.mphb-gallery-slider,.mphb-accommodation-type-gallery'
			);
			if(!root)return;

			const els=root.querySelectorAll('img,.slides li');
			items=[...new Set([...els].map(e=>getSrc(e)).filter(Boolean))];
			if(!items.length)return;

			els.forEach(el=>{
				el.classList.add('bsbt-lb-clickable');
				el.addEventListener('click',e=>{
					const i=items.indexOf(getSrc(el));
					if(i<0)return;
					e.preventDefault();
					e.stopPropagation();
					openAt(i);
				},true);
			});
		};

		window.addEventListener('load',()=>setTimeout(bind,500));
	})();
	</script>
	<?php

}, 999);
