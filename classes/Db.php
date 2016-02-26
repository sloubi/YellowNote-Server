<?php

class Db extends OAuth2\Storage\Pdo
{
    public function getUser($email)
    {
        if ( ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $stmt = $this->db->prepare(sprintf('SELECT * from %s where email=:email', $this->config['user_table']));
        $stmt->bindParam(':email', $email, PDO::PARAM_STR);
        $stmt->execute();

        if (!$userInfo = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            return false;
        }

        return array_merge(array(
            'user_id' => $userInfo['id']
        ), $userInfo);
    }

    protected function checkPassword($user, $password)
    {
        return $user['password'] == $password;
    }

    public function getNoteById($id_note, $user_id, $device_id)
    {
        $stmt = $this->db->prepare("
            SELECT n.*
            FROM {$this->config['note_table']} n
            INNER JOIN {$this->config['device_note_table']} dn ON n.id = dn.note_id
            INNER JOIN {$this->config['device_table']} d ON dn.device_id = d.id
            WHERE n.id = :id_note AND d.user_id = :user_id AND d.id = :device_id
            LIMIT 1
        ");
        $stmt->bindParam(':id_note', $id_note, PDO::PARAM_INT);
        $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $stmt->bindParam(':device_id', $device_id, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    // On assigne un device à un access_token
    public function setDeviceToToken($token, $user_id, $device_uid, $device_name)
    {
        $stmt = $this->db->prepare("SELECT id FROM {$this->config['device_table']} WHERE user_id = :user_id AND uid = :device_uid LIMIT 1");
        $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $stmt->bindParam(':device_uid', $device_uid, PDO::PARAM_STR);
        $stmt->execute();
        $id_device = $stmt->fetchColumn();

        // Si le device n'existe pas, on le crée
        if (!$id_device) {
            $id_device = $this->createDevice($device_uid, $device_name, $user_id);
        }

        // On assigne le device au token
        $stmt = $this->db->prepare("UPDATE {$this->config['access_token_table']} SET device_id = :device_id WHERE access_token = :access_token");
        $stmt->bindParam(':device_id', $id_device, PDO::PARAM_INT);
        $stmt->bindParam(':access_token', $token['access_token'], PDO::PARAM_STR);
        $stmt->execute();

        // On assigne le device au refresh token
        $stmt = $this->db->prepare("UPDATE {$this->config['refresh_token_table']} SET device_id = :device_id WHERE refresh_token = :refresh_token");
        $stmt->bindParam(':device_id', $id_device, PDO::PARAM_INT);
        $stmt->bindParam(':refresh_token', $token['refresh_token'], PDO::PARAM_STR);
        $stmt->execute();
    }

    public function assignDeviceToToken($token, $id_device)
    {
        // On assigne le device au token
        $stmt = $this->db->prepare("UPDATE {$this->config['access_token_table']} SET device_id = :device_id WHERE access_token = :access_token");
        $stmt->bindParam(':device_id', $id_device, PDO::PARAM_INT);
        $stmt->bindParam(':access_token', $token['access_token'], PDO::PARAM_STR);
        $stmt->execute();

        // On assigne le device au refresh token
        $stmt = $this->db->prepare("UPDATE {$this->config['refresh_token_table']} SET device_id = :device_id WHERE refresh_token = :refresh_token");
        $stmt->bindParam(':device_id', $id_device, PDO::PARAM_INT);
        $stmt->bindParam(':refresh_token', $token['refresh_token'], PDO::PARAM_STR);
        $stmt->execute();
    }

    /**
     * Création d'un device
     * @param  int $device_uid
     * @param  int $device_name
     * @param  int $user_id
     * @return int
     */
    protected function createDevice($device_uid, $device_name, $user_id)
    {
        $stmt = $this->db->prepare("INSERT INTO {$this->config['device_table']} (name, uid, user_id) VALUES (:name, :uid, :user_id)");
        $stmt->bindParam(':name', $device_name, PDO::PARAM_STR);
        $stmt->bindParam(':uid', $device_uid, PDO::PARAM_STR);
        $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $stmt->execute();
        $id_device = $this->db->lastInsertId();

        $devices = $this->getDevices($user_id);

        if (!empty($devices))
        {
            // On récupère les notes du premier device
            $firstDevice = array_shift($devices);
            $stmt = $this->db->prepare("
                SELECT note_id
                FROM {$this->config['device_note_table']}
                WHERE device_id = :device_id AND to_delete = 0");
            $stmt->bindParam(':device_id', $firstDevice, PDO::PARAM_INT);
            $stmt->execute();
            $notes = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

            // On les rajoute dans devices_notes pour indiquer qu'elles sont à synchroniser
            $stmt = $this->db->prepare("INSERT INTO {$this->config['device_note_table']} (device_id, note_id, to_sync)
                                        VALUES (:device_id, :note_id, 1)");
            foreach ($notes as $note_id)
            {
                $stmt->bindParam(':device_id', $id_device, PDO::PARAM_INT);
                $stmt->bindParam(':note_id', $note_id, PDO::PARAM_INT);
                $stmt->execute();
            }
        }

        return $id_device;
    }

    /**
     * Renvoie un tableau de notes avec comme clé la shared_key
     * @param  string $jsonNotes Le JSON reçu par l'API
     * @return array
     */
    public function getDeviceNotesFromJson($jsonNotes)
    {
        $notes = json_decode($jsonNotes, true);
        $deviceNotes = array();
        foreach ($notes as $note)
        {
            $deviceNotes[$note['shared_key']] = $note;
        }
        return $deviceNotes;
    }

    /**
     * Récupération des devices de l'utilisateur
     */
    public function getDevices($user_id)
    {
        // Récupération des devices
        $stmt = $this->db->prepare("
            SELECT id
            FROM {$this->config['device_table']}
            WHERE user_id = :user_id");
        $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    }

    /*
     * Les notes modifiées/ajoutées sur le serveur à récupérer sur le device
     */
    public function getNotesToSync($user_id, $device_id)
    {
        $stmt = $this->db->prepare("
            SELECT n.*, dn.to_delete
            FROM {$this->config['note_table']} n
            INNER JOIN {$this->config['device_note_table']} dn ON n.id = dn.note_id
            INNER JOIN {$this->config['device_table']} d ON d.id = dn.device_id
            WHERE to_sync = 1 AND d.user_id = :user_id AND d.id = :device_id");
        $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $stmt->bindParam(':device_id', $device_id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Crée ou met à jour les notes envoyés par le device sur le serveur
     */
    public function insertNotesFromDevice($notes)
    {
        if (empty($notes))
            return false;

        $stmt = $this->db->prepare("INSERT INTO {$this->config['note_table']}
                                    SET title = :title, content = :content, shared_key = :key,
                                        created_at = :created_at, updated_at = :updated_at
                                    ON DUPLICATE KEY UPDATE title = VALUES(title), content = VALUES(content),
                                        updated_at = VALUES(updated_at)");
        foreach ($notes as $note)
        {
            $stmt->bindValue(':title', $note['title'], PDO::PARAM_STR);
            $stmt->bindValue(':content', $note['content'], PDO::PARAM_STR);
            $stmt->bindValue(':key', $note['shared_key'], PDO::PARAM_STR);
            $stmt->bindValue(':created_at', $note['created_at'], PDO::PARAM_STR);
            $stmt->bindValue(':updated_at', $note['updated_at'], PDO::PARAM_STR);
            $stmt->execute();
        }
    }

    /**
     * Passage de la liste des notes locales en to_sync = 1 pour tous les autres devices sur le serveur
     */
    public function setToSyncForOtherDevices($devices, $notes, $current_device_id)
    {
        if (empty($notes))
            return false;

        // Récupération des id (cloud) des notes qui sont ajoutés ou modifiés
        $keys = array();
        foreach ($notes as $note)
            $keys[] = '"' . $note['shared_key'] . '"';
        $stmt = $this->db->prepare("SELECT id, shared_key FROM {$this->config['note_table']} WHERE shared_key IN (" . implode(',', $keys) . ")");
        $stmt->execute();
        $sharedKeys = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $this->db->prepare("INSERT INTO {$this->config['device_note_table']} (device_id, note_id, to_sync, to_delete)
                                    VALUES (:device_id, :note_id, :to_sync, :to_delete)
                                    ON DUPLICATE KEY UPDATE to_sync = VALUES(to_sync), to_delete = VALUES(to_delete)");
        foreach ($devices as $id_device)
        {
            foreach ($sharedKeys as $note)
            {
                $stmt->bindValue(':device_id', $id_device, PDO::PARAM_INT);
                $stmt->bindValue(':note_id', $note['id'], PDO::PARAM_INT);
                $stmt->bindValue(':to_sync', $id_device == $current_device_id ? 0 : 1, PDO::PARAM_INT);
                $stmt->bindValue(':to_delete', $notes[$note['shared_key']]['to_delete'], PDO::PARAM_INT);
                $stmt->execute();
            }
        }
    }

    /**
     * Passage des notes en "ok pour le device" sur le serveur pour les notes qu'on vient de récupérer
     */
    public function setSyncOKForDevice($notes, $device_id)
    {
        if (empty($notes))
            return false;

        $keys = array();
        foreach ($notes as $note)
            $keys[] = '"' . $note['shared_key'] . '"';
        $stmt = $this->db->prepare("
            UPDATE {$this->config['device_note_table']}
            SET to_sync = 0
            WHERE device_id = $device_id AND
            note_id IN (
                SELECT id FROM {$this->config['note_table']}  WHERE shared_key IN (" . implode(',', $keys) . ")
            )
        ");
        $stmt->execute();
    }

    /**
     * Si une note a été supprimée de tous les devices, on la supprime du serveur
     */
    public function cleanNotes($device_id)
    {
        $stmt = $this->db->prepare("
            DELETE FROM {$this->config['device_note_table']}
            WHERE device_id = $device_id AND to_delete = 1");
        $stmt->execute();

        $stmt = $this->db->prepare("
            DELETE FROM {$this->config['note_table']}
            WHERE id NOT IN (SELECT note_id FROM {$this->config['device_note_table']})
        ");
        $stmt->execute();
    }
}

