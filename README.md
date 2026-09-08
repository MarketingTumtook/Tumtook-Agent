# Tumtook Agents 1.1.0

แสดงทีมฝ่ายขายผ่าน shortcode [tumtook_agents] และตรวจข้อมูล API เบื้องหลังทุก 60 วินาที

## การอัปเดตและแคช

- งาน WordPress Cron ชื่อ tumtook_agents_refresh_event ดึง API โดยตรงทุกครั้งที่รัน ไม่รอให้แคชข้อมูล 30 นาทีหมดอายุ
- หน้าเว็บอ่านข้อมูลที่เก็บไว้ จึงไม่ต้องรอ API ในการเปิดหน้าตามปกติ มีเพียงครั้งแรกที่ไม่มีข้อมูลทั้งสองชุดที่อาจรอ API ตาม timeout 5 วินาที
- เปรียบเทียบเฉพาะข้อมูลที่ใช้แสดงการ์ด เมื่อข้อมูลเปลี่ยนจึงบันทึกข้อมูลใหม่ แล้วเรียก FlyingPress Purge::purge_urls และ Preload::preload_urls เฉพาะหน้าที่เกี่ยวข้อง
- ผลลัพธ์ที่ไม่มีคนเปิด flex หรือรายการว่างเป็นผลสำเร็จ และจะเขียนทับทั้งแคชหลักและแคชสำรองด้วยรายการว่าง
- เมื่อ API ตอบผิดพลาด จะคงข้อมูลสำเร็จล่าสุดโดยไม่ต่ออายุข้อมูลเก่า แคชหลักเก็บได้ 30 นาที และแคชสำรองเก็บได้ 12 ชั่วโมงนับจากดึงสำเร็จล่าสุด
- ตรวจสถานะ HTTP, JSON และโครงสร้างรายการก่อนแทนข้อมูลเดิม รองรับทั้งรายการ JSON โดยตรงและ object ที่มี items เป็นรายการ แต่ละรายการต้องมี name เป็น string และ flexEnabled เป็น boolean ตามรูปแบบที่ shortcode ใช้
- เมื่อ API ใช้งานไม่ได้ตั้งแต่ครั้งแรก จะจำกัดการลองจากผู้ชมเป็นช่วงละ 60 วินาที งานเบื้องหลังยังลองใหม่ตามรอบ
- ล็อกการดึงข้อมูลป้องกันคำขอพร้อมกัน และเก็บสถานะรอล้างแคชเพื่อให้ลองใหม่หาก FlyingPress เกิดข้อผิดพลาด

## หน้าที่ล้างแคช

ค้นหา shortcode ใน post_content และข้อมูล Elementor (_elementor_data) ของหน้าสาธารณะ รวมถึงจำหน้าจริงเมื่อ shortcode ถูกแสดงผ่าน template ไม่ล้างแคชทั้งเว็บไซต์

หากใช้ shortcode ผ่าน shared template หรือหน้า archive ที่มีแคชเดิมตั้งแต่ก่อนติดตั้งรุ่นนี้ ให้เปิดหน้าที่เกี่ยวข้องโดยข้ามแคชหนึ่งครั้งเพื่อให้ปลั๊กอินจำหน้า หรือกำหนด URL ผ่าน filter tumtook_agents_page_urls ในโค้ดของเว็บไซต์ สำหรับ archive ควรกำหนด URL ผ่าน filter นี้

ไม่สแกนรายการหน้าซ้ำเมื่อข้อมูลที่แสดงไม่เปลี่ยน รูปแบบการ์ดและการเรียงตาม LINE ID คงเดิม

## ติดตั้งหรืออัปเดต

1. อัปเดตโฟลเดอร์ tumtook-agents ทั้งชุด รวมถึงไฟล์ใหม่ includes/cache.php
2. เปิดหน้า Settings → Tumtook Agents ใน WordPress เพื่อให้รุ่นใหม่ตั้งงานอัตโนมัติ ไม่ต้อง deactivate/reactivate
3. งานครั้งแรกจะถูกตั้งให้พร้อมทำงานในประมาณหนึ่งวินาที เมื่อ WordPress Cron ถูกเรียกจะดึงข้อมูลล่าสุดและอัปเดตแคชหน้าที่ค้นพบ
4. ตรวจเวลา “อัปเดตสำเร็จล่าสุด” และ “รอบตรวจถัดไป” ในหน้าตั้งค่า หากมีข้อผิดพลาดจะมีข้อความแสดง

ปุ่ม Clear Cache ยังล้างแคชข้อมูลทั้งสองชุดและแคชหน้าที่เกี่ยวข้อง โดยคำขอถัดไปหรืองาน Cron จะดึงข้อมูลใหม่

## Cron บนเซิร์ฟเวอร์จริง

WP-Cron ตามปกติถูกกระตุ้นจากคำขอที่เข้าถึง WordPress จึงไม่รับประกันรอบหนึ่งนาทีหากไม่มีคนเข้าเว็บหรือคำขอถูกเสิร์ฟจาก page cache ทั้งหมด ต้องให้ตัวตั้งเวลาของโฮสติ้งเรียก WordPress ทุกนาทีเพื่อให้รอบตรวจสม่ำเสมอ

ตัวอย่าง Linux crontab (เปลี่ยน PHP binary และ path ให้ตรงกับเซิร์ฟเวอร์จริง):

    * * * * * /usr/bin/php /path/to/wordpress/wp-cron.php > /dev/null 2>&1

ถ้าโฮสติ้งมีงานเรียก wp-cron.php ทุกนาทีอยู่แล้ว ให้ใช้ตัวเดิม ไม่ต้องเพิ่มซ้ำ เมื่อยืนยันว่า Cron ของเซิร์ฟเวอร์ทำงานแล้ว จึงตั้ง DISABLE_WP_CRON เป็น true ได้หากต้องการหยุดการกระตุ้นจากผู้ชม การแก้ปลั๊กอินครั้งนี้ไม่ได้เปลี่ยน wp-config.php หรือตั้ง Cron บนเครื่องเซิร์ฟเวอร์

ทดสอบเรียกงานทันทีด้วย WP-CLI บนเว็บไซต์ที่ติดตั้งแล้ว:

    wp cron event run tumtook_agents_refresh_event --path=/path/to/wordpress

รอบ 60 วินาทีเป็นรอบตรวจข้อมูล การเปลี่ยนที่หน้าเว็บยังขึ้นกับเวลา API ตอบและการ purge/preload ที่สำเร็จ และมีผลเมื่อเปิดหรือรีโหลดหน้า ไม่ได้เปลี่ยนหน้าที่เปิดค้างไว้โดยอัตโนมัติ

## ทดสอบโค้ด

จาก WordPress root:

    php wp-content/plugins/tumtook-agents/tests/cache-regression.php
    php wp-content/plugins/tumtook-agents/tests/cache-regression.php --without-flyingpress

ใช้ WordPress hooks และตัวจัดตาราง Cron จริงจาก source ใน workspace แต่จำลอง HTTP, options/transients และ FlyingPress ส่วน SQL ค้นหาหน้ารันบน SQLite ในหน่วยความจำ ต้องมี PHP PDO SQLite ไม่ติดต่อ API หรือฐานข้อมูลเว็บไซต์จริง

เอกสารอ้างอิง:

- [FlyingPress: Purge and Preload](https://docs.flyingpress.com/en/articles/11406092-programmatically-purge-and-preload-cache)
- [WordPress: System Task Scheduler](https://developer.wordpress.org/plugins/cron/hooking-wp-cron-into-the-system-task-scheduler/)
