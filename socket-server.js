const { createServer } = require('http');
const { Server } = require('socket.io');
const cors = require('cors');
const fetch = require('node-fetch');

const httpServer = createServer();
const io = new Server(httpServer, {
    cors: {
        origin: [
            "http://localhost:5000",
            "http://localhost:5173",
            "http://localhost:3000",
            "https://nexacreators.com.br"
        ],
        methods: ["GET", "POST"],
        credentials: true
    },
    transports: ['websocket', 'polling'],
    pingTimeout: 60000,
    pingInterval: 25000,
    upgradeTimeout: 10000,
    allowUpgrades: true,
    maxHttpBufferSize: 1e8
});

httpServer.on('request', (req, res) => {
    if (req.url && req.url.startsWith('/socket.io')) { return; }
    console.log(`📥 HTTP Request: ${req.method} ${req.url}`);
    
    if (req.method === 'POST' && req.url === '/emit') {
        let body = '';
        req.on('data', chunk => {
            body += chunk.toString();
        });
        req.on('end', () => {
            try {
                console.log(`📦 Raw body received: ${body}`);
                const { event, data } = JSON.parse(body);
                
                console.log(`Received event from Laravel: ${event}`, data);
                console.log(`Event data roomId: ${data?.roomId}, event type: ${event}`);
                
                
                if (event === 'new_message' && data.roomId) {
                    
                    io.in(data.roomId).fetchSockets().then(sockets => {
                        console.log(`📊 Room ${data.roomId} has ${sockets.length} connected sockets:`, sockets.map(s => s.id));
                    });
                    
                    io.to(data.roomId).emit(event, data);
                    console.log(`📤 Emitted ${event} to room ${data.roomId}`);
                } else if (data.roomId) {
                    io.to(data.roomId).emit(event, data);
                    console.log(`📤 Emitted ${event} to room ${data.roomId}`);
                } else {
                    io.emit(event, data);
                    console.log(`📤 Broadcasted ${event} to all clients`);
                }
                
                res.writeHead(200, { 'Content-Type': 'application/json' });
                res.end(JSON.stringify({ success: true, message: 'Event emitted' }));
                console.log(`✅ HTTP Response sent: 200 OK`);
            } catch (error) {
                console.error('❌ Error processing event from Laravel:', error);
                res.writeHead(400, { 'Content-Type': 'application/json' });
                res.end(JSON.stringify({ success: false, error: error.message }));
                console.log(`❌ HTTP Response sent: 400 Bad Request`);
            }
        });
    } else {
        if (res.headersSent) { return; }
        console.log(`❌ HTTP 404: ${req.method} ${req.url}`);
        res.writeHead(404);
        res.end();
    }
});

const connectedUsers = new Map();
const userRooms = new Map();

const userNotificationRooms = new Map();

global.socket_server = io;

io.on('connection', (socket) => {
    console.log(`New client connected: ${socket.id}`);
    
    
    socket.on('error', (error) => {
        console.error(`Socket error for ${socket.id}:`, error);
    });
    
    
    socket.on('user_join', (data) => {
        const { userId, userRole } = data;
        
        connectedUsers.set(socket.id, {
            userId,
            userRole,
            socketId: socket.id
        });
        
        userRooms.set(userId, socket.id);
        
        
        const notificationRoom = `user_${userId}`;
        socket.join(notificationRoom);
        userNotificationRooms.set(userId, notificationRoom);
        
    });

    
    socket.on('join_room', (roomId) => {
        if (!roomId || typeof roomId !== 'string') {
            console.error(`Invalid roomId provided: ${roomId}`);
            return;
        }
        
        socket.join(roomId);
        console.log(`🚪 Socket ${socket.id} joined room ${roomId}`);
        
        
        io.in(roomId).fetchSockets().then(sockets => {
            console.log(`📊 Room ${roomId} now has ${sockets.length} sockets:`, sockets.map(s => s.id));
        }).catch(error => {
            console.error(`Error fetching sockets for room ${roomId}:`, error);
        });
    });

    
    socket.on('leave_room', (roomId) => {
        if (!roomId || typeof roomId !== 'string') {
            console.error(`Invalid roomId provided for leave: ${roomId}`);
            return;
        }
        
        socket.leave(roomId);
        console.log(`Socket ${socket.id} left room ${roomId}`);
    });

    
    socket.on('send_message', (data) => {
        console.log('📨 Received send_message event:', data);
        
        if (!data || !data.roomId || !data.message) {
            console.error('❌ Invalid message data received:', data);
            return;
        }
        
        const { roomId, message, senderId, senderName, senderAvatar, messageType, fileData } = data;
        console.log(`📤 Broadcasting message to room ${roomId} from user ${senderId}`);
        
        
        
        io.to(roomId).emit('new_message', {
            roomId,
            messageId: data.messageId, 
            message,
            senderId,
            senderName,
            senderAvatar,
            messageType,
            fileData,
            timestamp: new Date().toISOString()
        });
        
        
        io.in(roomId).fetchSockets().then(sockets => {
            const otherSockets = sockets.filter(s => s.id !== socket.id);
            console.log(`📊 Room ${roomId} has ${sockets.length} total sockets, ${otherSockets.length} other sockets`);
        });
    });

    
    socket.on('typing_start', (data) => {
        const { roomId, userId, userName } = data;
        
        
        io.in(roomId).fetchSockets().then(sockets => {
            const otherSockets = sockets.filter(s => s.id !== socket.id);
        });
        
        
        socket.to(roomId).emit('user_typing', {
            roomId,
            userId,
            userName,
            isTyping: true
        });
        
    });

    socket.on('typing_stop', (data) => {
        const { roomId, userId, userName } = data;
        
        
        io.in(roomId).fetchSockets().then(sockets => {
            const otherSockets = sockets.filter(s => s.id !== socket.id);
        });
        
        
        socket.to(roomId).emit('user_typing', {
            roomId,
            userId,
            userName,
            isTyping: false
        });
        
    });

    
    socket.on('mark_read', (data) => {
        const { roomId, messageIds, userId } = data;
        
        
        io.to(roomId).emit('messages_read', {
            roomId,
            messageIds,
            readBy: userId,
            timestamp: new Date().toISOString()
        });
        
    });

    
    socket.on('file_upload_progress', (data) => {
        const { roomId, fileName, progress } = data;
        
        
        socket.to(roomId).emit('file_upload_progress', {
            roomId,
            fileName,
            progress
        });
    });

    
    socket.on('offer_created', (data) => {
        const { roomId, offerData, senderId } = data;
        
        
        io.to(roomId).emit('offer_created', {
            roomId,
            offerData,
            senderId,
            timestamp: new Date().toISOString()
        });
        
        
        if (offerData.creator_id) {
            const creatorNotificationRoom = `user_${offerData.creator_id}`;
            io.to(creatorNotificationRoom).emit('new_offer_notification', {
                offerData,
                senderId,
                timestamp: new Date().toISOString()
            });
        }
    });

    
    socket.on('offer_accepted', (data) => {
        const { roomId, offerData, contractData, senderId } = data;
        
        
        io.to(roomId).emit('offer_accepted', {
            roomId,
            offerData,
            contractData,
            senderId,
            timestamp: new Date().toISOString()
        });
        
        
        if (offerData.brand_id) {
            const brandNotificationRoom = `user_${offerData.brand_id}`;
            io.to(brandNotificationRoom).emit('offer_accepted_notification', {
                offerData,
                contractData,
                senderId,
                timestamp: new Date().toISOString()
            });
        }
    });

    
    socket.on('send_offer_acceptance_message', (data) => {
        console.log('Received send_offer_acceptance_message event:', data);
        
        const { roomId, offerData, contractData, senderId, senderName, senderAvatar } = data;
        
        
        console.log(`Broadcasting offer_acceptance_message to room ${roomId}`);
        io.to(roomId).emit('offer_acceptance_message', {
            roomId,
            offerData,
            contractData,
            senderId,
            senderName,
            senderAvatar,
            timestamp: new Date().toISOString()
        });
        
        
        io.in(roomId).fetchSockets().then(sockets => {
            console.log(`Room ${roomId} has ${sockets.length} sockets:`, sockets.map(s => s.id));
        });
    });

    
    socket.on('offer_rejected', (data) => {
        const { roomId, offerData, senderId, rejectionReason } = data;
        
        
        io.to(roomId).emit('offer_rejected', {
            roomId,
            offerData,
            senderId,
            rejectionReason,
            timestamp: new Date().toISOString()
        });
        
        
        if (offerData.brand_id) {
            const brandNotificationRoom = `user_${offerData.brand_id}`;
            io.to(brandNotificationRoom).emit('offer_rejected_notification', {
                offerData,
                senderId,
                rejectionReason,
                timestamp: new Date().toISOString()
            });
        }
    });

    
    socket.on('offer_cancelled', (data) => {
        const { roomId, offerData, senderId } = data;
        
        
        io.to(roomId).emit('offer_cancelled', {
            roomId,
            offerData,
            senderId,
            timestamp: new Date().toISOString()
        });
        
        
        if (offerData.creator_id) {
            const creatorNotificationRoom = `user_${offerData.creator_id}`;
            io.to(creatorNotificationRoom).emit('offer_cancelled_notification', {
                offerData,
                senderId,
                timestamp: new Date().toISOString()
            });
        }
    });

    
    socket.on('contract_completed', (data) => {
        const { roomId, contractData, senderId } = data;
        
        
        io.to(roomId).emit('contract_completed', {
            roomId,
            contractData,
            senderId,
            timestamp: new Date().toISOString()
        });
        
        
        if (contractData.creator_id) {
            const creatorNotificationRoom = `user_${contractData.creator_id}`;
            io.to(creatorNotificationRoom).emit('contract_completed_notification', {
                contractData,
                senderId,
                timestamp: new Date().toISOString()
            });
        }
        
        if (contractData.brand_id) {
            const brandNotificationRoom = `user_${contractData.brand_id}`;
            io.to(brandNotificationRoom).emit('contract_completed_notification', {
                contractData,
                senderId,
                timestamp: new Date().toISOString()
            });
        }
    });

    
    socket.on('contract_terminated', (data) => {
        const { roomId, contractData, senderId, terminationReason } = data;
        
        
        io.to(roomId).emit('contract_terminated', {
            roomId,
            contractData,
            senderId,
            terminationReason,
            timestamp: new Date().toISOString()
        });
        
        
        if (contractData.creator_id) {
            const creatorNotificationRoom = `user_${contractData.creator_id}`;
            io.to(creatorNotificationRoom).emit('contract_terminated_notification', {
                contractData,
                senderId,
                terminationReason,
                timestamp: new Date().toISOString()
            });
        }
        
        if (contractData.brand_id) {
            const brandNotificationRoom = `user_${contractData.brand_id}`;
            io.to(brandNotificationRoom).emit('contract_terminated_notification', {
                contractData,
                senderId,
                terminationReason,
                timestamp: new Date().toISOString()
            });
        }
    });

    
    socket.on('contract_activated', (data) => {
        const { roomId, contractData, senderId } = data;
        
        
        io.to(roomId).emit('contract_activated', {
            roomId,
            contractData,
            senderId,
            timestamp: new Date().toISOString()
        });
        
        
        if (contractData.creator_id) {
            const creatorNotificationRoom = `user_${contractData.creator_id}`;
            io.to(creatorNotificationRoom).emit('contract_activated_notification', {
                contractData,
                senderId,
                timestamp: new Date().toISOString()
            });
        }
        
        if (contractData.brand_id) {
            const brandNotificationRoom = `user_${contractData.brand_id}`;
            io.to(brandNotificationRoom).emit('contract_activated_notification', {
                contractData,
                senderId,
                timestamp: new Date().toISOString()
            });
        }
    });

    
    socket.on('contract_status_update', (data) => {
        const { roomId, contractData, terminationReason, timestamp } = data;
        
        
        io.to(roomId).emit('contract_status_update', {
            roomId,
            contractData,
            terminationReason,
            timestamp: timestamp || new Date().toISOString()
        });
        
        
        if (contractData.creator_id) {
            const creatorNotificationRoom = `user_${contractData.creator_id}`;
            io.to(creatorNotificationRoom).emit('contract_status_update_notification', {
                contractData,
                terminationReason,
                timestamp: timestamp || new Date().toISOString()
            });
        }
        
        if (contractData.brand_id) {
            const brandNotificationRoom = `user_${contractData.brand_id}`;
            io.to(brandNotificationRoom).emit('contract_status_update_notification', {
                contractData,
                terminationReason,
                timestamp: timestamp || new Date().toISOString()
            });
        }
    });

    
    socket.on('disconnect', (reason) => {
        const userData = connectedUsers.get(socket.id);
        
        if (userData) {
            const { userId } = userData;
            
            
            connectedUsers.delete(socket.id);
            userRooms.delete(userId);
            userNotificationRooms.delete(userId);
            
            console.log(`User ${userId} disconnected: ${reason}`);
        }
    });
});

const PORT = process.env.SOCKET_PORT || 3000;

httpServer.listen(PORT, () => {
    console.log(`Socket.IO server running on port ${PORT}`);
    console.log(`CORS enabled for: http://localhost:5000, http://localhost:5173, http://nexacreators.com.br, https://nexacreators.com.br`);
});

module.exports = { io, connectedUsers, userRooms, userNotificationRooms }; 