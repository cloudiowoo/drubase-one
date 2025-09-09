const { Client } = require("pg");

async function testDatabaseNotification() {
    const client = new Client({
        connectionString: process.env.DATABASE_URL
    });
    
    try {
        await client.connect();
        console.log("已连接到数据库");
        
        // 监听realtime_changes通知
        await client.query("LISTEN realtime_changes");
        console.log("开始监听realtime_changes通知");
        
        client.on("notification", (msg) => {
            console.log("🔔 收到数据库通知:", {
                channel: msg.channel,
                payload: msg.payload
            });
            try {
                const payload = JSON.parse(msg.payload);
                console.log("📄 解析后的通知内容:", JSON.stringify(payload, null, 2));
            } catch (e) {
                console.log("📄 原始通知内容:", msg.payload);
            }
        });
        
        // 在另一个连接中触发通知
        const client2 = new Client({
            connectionString: process.env.DATABASE_URL
        });
        
        await client2.connect();
        console.log("第二个连接已建立");
        
        // 等待2秒然后手动发送测试通知
        setTimeout(async () => {
            try {
                console.log("发送测试通知...");
                await client2.query('SELECT pg_notify(\'realtime_changes\', \'{"test": "manual_notification"}\')');
                console.log("✅ 测试通知已发送");
            } catch (e) {
                console.error("❌ 发送测试通知失败:", e);
            }
        }, 2000);
        
        // 等待5秒然后执行数据库更新
        setTimeout(async () => {
            try {
                console.log("执行数据库更新...");
                const result = await client2.query("UPDATE baas_00403b_activities SET description = 'Test update ' || NOW() WHERE id = 3");
                console.log("✅ 更新结果:", result.rowCount, "行被更新");
            } catch (e) {
                console.error("❌ 数据库更新失败:", e);
            }
        }, 5000);
        
        // 检查触发器是否存在
        setTimeout(async () => {
            try {
                console.log("检查触发器状态...");
                const triggerResult = await client2.query(`
                    SELECT t.tgname as trigger_name, 
                           c.relname as table_name,
                           p.proname as function_name
                    FROM pg_trigger t
                    JOIN pg_class c ON t.tgrelid = c.oid
                    JOIN pg_proc p ON t.tgfoid = p.oid
                    WHERE c.relname = 'baas_00403b_activities'
                    AND t.tgname LIKE '%realtime%'
                `);
                
                console.log("🔧 触发器信息:", triggerResult.rows);
                
                // 检查触发器函数是否存在
                const functionResult = await client2.query(`
                    SELECT proname, prosrc 
                    FROM pg_proc 
                    WHERE proname = 'notify_realtime_change'
                `);
                
                console.log("🔧 触发器函数信息:", functionResult.rows.length > 0 ? "存在" : "不存在");
                if (functionResult.rows.length > 0) {
                    console.log("📝 函数源码:", functionResult.rows[0].prosrc.substring(0, 200) + "...");
                }
                
            } catch (e) {
                console.error("❌ 检查触发器失败:", e);
            }
        }, 7000);
        
        // 12秒后关闭连接
        setTimeout(async () => {
            await client2.end();
            await client.end();
            console.log("连接已关闭");
            process.exit(0);
        }, 12000);
        
    } catch (error) {
        console.error("❌ 数据库连接失败:", error);
        process.exit(1);
    }
}

testDatabaseNotification();